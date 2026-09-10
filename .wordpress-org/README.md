# WordPress.org listing assets

Images in this directory are published to the plugin directory listing, not bundled
into the plugin. They live in the `assets/` folder of the WordPress.org Subversion
repository, which is why they are kept apart from the plugin's own `assets/` folder
of scripts and styles.

Anything changed here publishes on its own when merged to `main`, without needing a
new plugin version. See `.github/workflows/assets.yml`.

## Required files

| File | Size | Purpose |
| --- | --- | --- |
| `icon-128x128.png` | 128x128 | Icon in search results and the plugin card |
| `icon-256x256.png` | 256x256 | Icon on high density displays |
| `banner-772x250.png` | 772x250 | Header on the plugin page |
| `banner-1544x500.png` | 1544x500 | Header on high density displays |

An `icon.svg` may be supplied instead of the two PNG icons.

## Screenshots

Name them `screenshot-1.png`, `screenshot-2.png` and so on. Each one needs a matching
numbered line under a `== Screenshots ==` section in `readme.txt`, in the same order,
or the captions will not line up.

Suggested set for this plugin:

1. The card fields at checkout, showing the hosted iframe.
2. The gateway settings screen with the sandbox and production credential sections.
3. The CardPointe panel on the order screen, showing capture and void.
4. A saved payment method under My Account.

Keep file sizes modest. The directory has no hard limit but large images slow the
listing down for everyone.
