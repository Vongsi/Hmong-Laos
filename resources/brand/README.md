# Daim Av Hlub logo

The symbol is the paj ntaub diamond: a diamond frame with a four-petal flower and a small centre, in the
website's colours (navy `#1E2656`, red `#B8263F`, green `#2E7A58`; on dark backgrounds `#ECEEF8`,
`#EE6A80`, `#5CC596`).

| File | Use |
| --- | --- |
| `symbol.svg` | The symbol on light backgrounds (print, documents, social posts). |
| `symbol-on-dark.svg` | The symbol on dark or photo backgrounds. |
| `app-icon.svg` | Rounded app icon → `public/icons/icon-192.png`, `icon-512.png`. |
| `app-icon-square.svg` | Full-bleed app icon, for phones that round the corners themselves → `maskable-512.png`, `apple-touch-icon.png` (180). |
| `badge.svg` | White symbol on transparent, for Android notifications → `badge-96.png`. |
| `og-default.html` | The link preview image for pages without a photo → `public/images/og-default.png` (1200×630). |
| `watermark.html` | The mark on the site's photos → `public/images/watermark.png` (transparent; Glide sizes and places it, see `app/Support/Watermark.php`). |

The website header draws the symbol inline (`resources/views/layout.antlers.html`) with the theme's colours, so
it follows dark mode; `public/icons/favicon.svg` does the same for the browser tab. `favicon-32.png` is the
fallback for browsers without SVG icons.

The PNGs are screenshots of these files at their exact sizes (with a transparent background, apart from the app
icons and the preview image) in a headless Chrome. After changing a file here, render the PNGs again and bump
`VERSION` in `public/sw.js` so installed apps pick up the new icons.
