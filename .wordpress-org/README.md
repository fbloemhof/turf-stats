# WordPress.org listing assets

Contents of this directory are copied to the top-level `assets/` folder in
the wordpress.org SVN repo by the deploy workflow (`ASSETS_DIR` default of
`10up/action-wordpress-plugin-deploy`) - they are never bundled into the
plugin zip itself.

`icon.svg` / `banner.svg` are the source vectors; the PNGs are rendered
from them (green turf gradient, grass-line silhouette, ascending trend
line - matches the plugin's analytics/growth theme). Regenerate after
editing the SVGs with any rasterizer, e.g.:

```
npx sharp-cli -i icon.svg -o icon-128x128.png resize 128 128
npx sharp-cli -i icon.svg -o icon-256x256.png resize 256 256
npx sharp-cli -i banner.svg -o banner-772x250.png resize 772 250
npx sharp-cli -i banner.svg -o banner-1544x500.png resize 1544 500
```

Still missing: `screenshot-1.png`, `screenshot-2.png`, ... matched to
numbered `== Screenshots ==` entries in `readme.txt` (add that section
once screenshots exist). Not required for the SVN sync to work.
