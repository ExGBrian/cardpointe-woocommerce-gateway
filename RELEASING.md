# Releasing

Publishing is automated, but a release is only as good as the checks done before the
tag. Work down this list in order.

## One time setup

1. Submit the plugin at https://wordpress.org/plugins/developers/add/ and wait for the
   review team to approve it. Nothing below can publish until the SVN repository exists.
2. In the GitHub repository, under Settings, Secrets and variables, Actions, add:
   - `SVN_USERNAME`, your WordPress.org account name
   - `SVN_PASSWORD`, that account's password
3. Confirm the `SLUG` in `.github/workflows/deploy.yml` matches the slug the review
   team assigned. It is currently `paradox-cardpointe-gateway`.
4. Add the banner and icon images described in `.wordpress-org/README.md`.

## Before every release

- [ ] `readme.txt` **Tested up to** names the current WordPress release, and the plugin
      has actually been run against it.
- [ ] `readme.txt` **Contributors** lists real WordPress.org usernames.
- [ ] The short description under the readme headers is 150 characters or fewer.
- [ ] `WC tested up to` in the plugin header names the current WooCommerce release.
- [ ] The sandbox checklist has been worked through end to end: a charge, an
      authorize then capture, a void, a full refund, a partial refund after
      settlement, a saved card reused, and a declined card.
- [ ] `WooCommerce, Status, Logs` shows no card numbers, security codes, passwords or
      full tokens.
- [ ] CI is green on `main`.

## Cutting the release

1. Bump the version in **both** places, they must match:
   - `Version:` in `paradox-cardpointe-gateway.php`
   - `Stable tag:` in `readme.txt`
2. Add a `== Changelog ==` entry for the new version in `readme.txt`. The deploy
   workflow refuses to publish without one.
3. Merge to `main`.
4. Tag and push:

   ```sh
   git tag v1.0.1
   git push origin v1.0.1
   ```

The deploy workflow verifies the tag, the plugin header and the readme all agree,
then publishes to the plugin directory. Watch the run in the Actions tab; a failed
version check stops the release before anything is written to SVN.

## Notes

- The package published to WordPress.org excludes everything in `.distignore`, so the
  workflows, Composer files and this document never reach users.
- Asset changes publish on their own from `.wordpress-org/`, no version bump needed.
- There is no build step. The block checkout scripts are plain JavaScript on purpose,
  which keeps the published source readable, as the review guidelines require.
