<?php
/**
 * Apple Pay merchant identity certificate: inspection and storage.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\ApplePay;

defined( 'ABSPATH' ) || exit;

/**
 * The merchant identity certificate and its private key live in one PEM file.
 *
 * Most failed Apple Pay setups are PEM problems: the binary .cer uploaded as is, the
 * key left out, the key still passphrase protected, or the Payment Processing
 * Certificate used by mistake. inspect() names each of those precisely instead of
 * letting them surface later as an opaque TLS error from Apple.
 *
 * The file holds a private key, so store() never writes it somewhere a browser can
 * reach by default: one level above the web root when the server allows it, otherwise
 * a locked-down uploads folder under an unguessable name.
 */
final class CertificateStore {

	/** File name used when storing above the web root. */
	const FILENAME = 'paradox-cardpointe-apple-pay.pem';

	/** Folder under wp-content/uploads used when nothing above the web root is writable. */
	const FALLBACK_DIR = 'paradox-cardpointe-private';

	/** A certificate plus key is a few kilobytes; anything much larger is not a PEM. */
	const MAX_BYTES = 65536;

	const CERT_PATTERN = '/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s';
	const KEY_PATTERN  = '/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----.+?-----END (?:RSA |EC )?PRIVATE KEY-----/s';

	/**
	 * Directory the web server serves this site from, with a trailing slash.
	 */
	public static function web_root(): string {
		$abspath = realpath( ABSPATH );
		$abspath = trailingslashit( $abspath ? $abspath : ABSPATH );

		$root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! $root ) {
			return $abspath;
		}
		$root = trailingslashit( $root );

		// WordPress in a subdirectory still sits inside the document root. When it does not
		// (CLI, unusual aliases), the WordPress directory is the better answer.
		return 0 === strpos( $abspath, $root ) ? $root : $abspath;
	}

	/**
	 * Whether a path can be reached through the web server.
	 *
	 * @param string $path File path.
	 */
	public static function is_inside_web_root( string $path ): bool {
		$real = realpath( $path );
		$real = $real ? $real : $path;
		return 0 === strpos( $real, self::web_root() );
	}

	/**
	 * Checks PEM text and reports exactly what is wrong with it.
	 *
	 * @param string $pem File contents.
	 * @return array {
	 *     @type string[] $errors      Problems that stop Apple Pay from working.
	 *     @type string[] $warnings    Things worth knowing that do not block it.
	 *     @type string   $merchant_id Merchant identifier read from the certificate.
	 *     @type string   $common_name Certificate common name.
	 *     @type int      $expires     Expiry as a Unix timestamp, 0 when unknown.
	 *     @type string   $normalised  Certificate and key only, leaf first (never sent to the browser).
	 * }
	 */
	public static function inspect( string $pem ): array {
		$report = array(
			'errors'      => array(),
			'warnings'    => array(),
			'merchant_id' => '',
			'common_name' => '',
			'expires'     => 0,
			'normalised'  => '',
		);

		if ( '' === trim( $pem ) ) {
			$report['errors'][] = __( 'The file is empty.', 'paradox-cardpointe-gateway-for-woocommerce' );
			return $report;
		}
		if ( strlen( $pem ) > self::MAX_BYTES ) {
			$report['errors'][] = __( 'The file is too large to be a certificate.', 'paradox-cardpointe-gateway-for-woocommerce' );
			return $report;
		}

		preg_match_all( self::CERT_PATTERN, $pem, $cert_blocks );
		preg_match( self::KEY_PATTERN, $pem, $key_block );
		$encrypted = false !== strpos( $pem, '-----BEGIN ENCRYPTED PRIVATE KEY-----' ) || false !== strpos( $pem, 'Proc-Type: 4,ENCRYPTED' );

		if ( empty( $cert_blocks[0] ) ) {
			$report['errors'][] = __( 'No certificate was found in the file. It must contain a block that starts with -----BEGIN CERTIFICATE-----. The .cer file Apple gives you is in binary format and has to be converted to PEM first.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		if ( $encrypted ) {
			$report['errors'][] = __( 'The private key in this file is protected by a passphrase. Export it again without one by adding -nodes to the openssl command.', 'paradox-cardpointe-gateway-for-woocommerce' );
		} elseif ( empty( $key_block[0] ) ) {
			$report['errors'][] = __( 'No private key was found in the file. The PEM must contain both the certificate and the private key it was created with.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		if ( ! empty( $report['errors'] ) ) {
			return $report;
		}

		if ( ! function_exists( 'openssl_x509_read' ) ) {
			$report['warnings'][] = __( 'PHP OpenSSL is not available, so the certificate could not be checked in depth.', 'paradox-cardpointe-gateway-for-woocommerce' );
			$report['normalised'] = $cert_blocks[0][0] . "\n" . $key_block[0] . "\n";
			return $report;
		}

		$key = openssl_pkey_get_private( $key_block[0] );
		if ( false === $key ) {
			$report['errors'][] = __( 'The private key could not be read. Make sure it was exported without a passphrase and the file was not altered.', 'paradox-cardpointe-gateway-for-woocommerce' );
			return $report;
		}

		// A PEM exported from a .p12 can carry Apple's intermediate certificates as well.
		// The one that matters is the one this private key belongs to.
		$leaf       = null;
		$leaf_block = '';
		foreach ( $cert_blocks[0] as $index => $block ) {
			$candidate = openssl_x509_read( $block );
			if ( false !== $candidate && openssl_x509_check_private_key( $candidate, $key ) ) {
				$leaf       = $candidate;
				$leaf_block = $block;
				if ( $index > 0 ) {
					$report['warnings'][] = __( 'The file contains several certificates and the Merchant Identity Certificate is not the first one. Upload the file here to have it tidied automatically.', 'paradox-cardpointe-gateway-for-woocommerce' );
				}
				break;
			}
		}
		if ( null === $leaf ) {
			$report['errors'][] = __( 'The private key does not belong to the certificate in this file. Use the key that was generated together with the certificate signing request you gave Apple.', 'paradox-cardpointe-gateway-for-woocommerce' );
			return $report;
		}

		$parsed  = openssl_x509_parse( $leaf );
		$subject = is_array( $parsed ) && isset( $parsed['subject'] ) && is_array( $parsed['subject'] ) ? $parsed['subject'] : array();

		$report['common_name'] = self::subject_value( $subject, 'CN' );
		$report['merchant_id'] = self::subject_value( $subject, 'UID' );
		$report['expires']     = is_array( $parsed ) && isset( $parsed['validTo_time_t'] ) ? (int) $parsed['validTo_time_t'] : 0;
		$report['normalised']  = $leaf_block . "\n" . $key_block[0] . "\n";

		if ( false !== stripos( $report['common_name'], 'Payment Processing' ) ) {
			$report['errors'][] = __( 'This is the Payment Processing Certificate. That one goes to Fiserv. The file needed here is the Merchant Identity Certificate.', 'paradox-cardpointe-gateway-for-woocommerce' );
		} elseif ( false === stripos( $report['common_name'], 'Merchant Identity' ) ) {
			$report['warnings'][] = sprintf(
				/* translators: %s: certificate common name */
				__( 'The certificate does not look like an Apple Pay Merchant Identity Certificate (its name is "%s").', 'paradox-cardpointe-gateway-for-woocommerce' ),
				$report['common_name']
			);
		}

		if ( $report['expires'] > 0 ) {
			if ( $report['expires'] < time() ) {
				$report['errors'][] = sprintf(
					/* translators: %s: date */
					__( 'The certificate expired on %s. Create a new Merchant Identity Certificate in the Apple Developer portal.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					wp_date( get_option( 'date_format' ), $report['expires'] )
				);
			} elseif ( $report['expires'] < time() + 30 * DAY_IN_SECONDS ) {
				$report['warnings'][] = sprintf(
					/* translators: %s: date */
					__( 'The certificate expires on %s. Renew it in the Apple Developer portal before then.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					wp_date( get_option( 'date_format' ), $report['expires'] )
				);
			}
		}

		return $report;
	}

	/**
	 * Inspects the PEM at a path, adding file-level problems.
	 *
	 * @param string $path Path from the settings.
	 * @return array Same shape as inspect().
	 */
	public static function inspect_file( string $path ): array {
		static $cache = array();
		if ( isset( $cache[ $path ] ) ) {
			return $cache[ $path ];
		}

		$blank = array(
			'errors'      => array(),
			'warnings'    => array(),
			'merchant_id' => '',
			'common_name' => '',
			'expires'     => 0,
			'normalised'  => '',
		);

		if ( '' === $path ) {
			$blank['errors'][] = __( 'No certificate has been uploaded yet.', 'paradox-cardpointe-gateway-for-woocommerce' );
			$cache[ $path ]    = $blank;
			return $blank;
		}
		if ( ! @is_readable( $path ) || ! @is_file( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir raises a warning for paths outside it.
			$blank['errors'][] = __( 'The file cannot be read at this path. Check that the path is complete, the file exists, and the web server user is allowed to read it.', 'paradox-cardpointe-gateway-for-woocommerce' );
			$cache[ $path ]    = $blank;
			return $blank;
		}
		if ( filesize( $path ) > self::MAX_BYTES ) {
			$blank['errors'][] = __( 'The file is too large to be a certificate.', 'paradox-cardpointe-gateway-for-woocommerce' );
			$cache[ $path ]    = $blank;
			return $blank;
		}

		$report = self::inspect( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

		if ( self::is_inside_web_root( $path ) && ! self::is_in_fallback_dir( $path ) ) {
			$report['warnings'][] = __( 'This file is inside the web root, so anyone who guesses its address may be able to download your private key. Move it above the web root, or upload it here and it will be stored safely.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		$cache[ $path ] = $report;
		return $report;
	}

	/**
	 * Writes an already inspected PEM to a private location.
	 *
	 * @param string $pem Normalised PEM (inspect()['normalised']).
	 * @return string Absolute path of the stored file.
	 *
	 * @throws \RuntimeException When no location is writable.
	 */
	public static function store( string $pem ): string {
		// First choice: one level above the web root, where no URL can reach it.
		$parent = dirname( untrailingslashit( self::web_root() ) );
		if ( '' !== $parent && '/' !== $parent && '.' !== $parent && @is_dir( $parent ) && @is_writable( $parent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$path = trailingslashit( $parent ) . self::FILENAME;
			if ( self::write( $path, $pem ) ) {
				self::purge_fallback_dir();
				return $path;
			}
		}

		// Otherwise a folder that refuses web requests, under a name nobody can guess.
		$dir = self::fallback_dir();
		if ( '' !== $dir && wp_mkdir_p( $dir ) ) {
			self::protect_dir( $dir );
			self::purge_fallback_dir();
			$path = trailingslashit( $dir ) . 'apple-pay-' . strtolower( wp_generate_password( 32, false, false ) ) . '.pem';
			if ( self::write( $path, $pem ) ) {
				return $path;
			}
		}

		throw new \RuntimeException( __( 'The certificate could not be saved: neither the folder above the web root nor the uploads folder is writable. Place the file on the server yourself and enter its path.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
	}

	/**
	 * Whether the plugin created the file at this path (and may therefore delete it).
	 *
	 * @param string $path Path.
	 */
	public static function is_managed( string $path ): bool {
		return '' !== $path && ( self::FILENAME === basename( $path ) || self::is_in_fallback_dir( $path ) );
	}

	/**
	 * Private folder used when nothing above the web root is writable.
	 */
	public static function fallback_dir(): string {
		$uploads = wp_upload_dir( null, false );
		return empty( $uploads['basedir'] ) ? '' : trailingslashit( $uploads['basedir'] ) . self::FALLBACK_DIR;
	}

	/**
	 * Whether a path is inside the private uploads folder.
	 *
	 * @param string $path Path.
	 */
	private static function is_in_fallback_dir( string $path ): bool {
		$dir = self::fallback_dir();
		return '' !== $dir && 0 === strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $dir ) ) );
	}

	/**
	 * Writes the file and restricts it to the owner.
	 *
	 * @param string $path Destination.
	 * @param string $pem  Contents.
	 */
	private static function write( string $path, string $pem ): bool {
		$written = @file_put_contents( $path, $pem, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return false;
		}
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		return true;
	}

	/**
	 * Refuses web requests to the private folder (Apache) and hides its listing.
	 *
	 * @param string $dir Directory.
	 */
	private static function protect_dir( string $dir ) {
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
			@file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Removes certificates this plugin stored earlier, so old private keys do not pile up.
	 */
	private static function purge_fallback_dir() {
		$dir = self::fallback_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( trailingslashit( $dir ) . 'apple-pay-*.pem' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * A subject field as a string (OpenSSL returns an array when a field repeats).
	 *
	 * @param array  $subject Parsed subject.
	 * @param string $field   Field name.
	 */
	private static function subject_value( array $subject, string $field ): string {
		if ( ! isset( $subject[ $field ] ) ) {
			return '';
		}
		$value = $subject[ $field ];
		return is_array( $value ) ? (string) reset( $value ) : (string) $value;
	}
}
