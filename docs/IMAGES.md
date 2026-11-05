# Demo images: licensing and replacement

## What ships

`nexora-commerce-toolkit/demo/images/` holds 88 JPEGs (about 6 MB):

| Set | Count | Size |
|---|---|---|
| Product main images (light studio) | 33 | 1000×1000 |
| Product detail images (night studio) | 33 | 1000×1000 |
| Variation color images | 8 | 1000×1000 |
| Category tiles | 6 | 800×800 |
| Hero, campaign, CTA banners | 3 | 1600×1200 / 1800×900 |
| Journal post images | 5 | 1600×1000 |

**License:** original vector illustrations created for this project and released under **CC0** (no attribution required). They contain no third-party photos, logos or brand marks, so there is nothing to verify before distributing the theme.

The theme folder also includes `assets/images/hero-fallback.jpg` (same artwork), used only when no hero image is set.

When imported, WordPress generates responsive sizes. With *Generate WebP image sizes* on (Customize → Freshtastic Theme → Performance) and a server that supports WebP, those sizes are WebP.

## Regenerating or restyling the artwork

The images are generated from code in `tools/image-generator/`:

- `art.mjs` contains one vector illustration per product type (headphones, watch, sneakers and so on).
- `generate.mjs` composes them into scenes using colors from `demo/data/products.json` and renders them with headless Chrome. macOS `sips` converts them to JPEG.

```bash
node tools/image-generator/generate.mjs              # all images (~6 min)
node tools/image-generator/generate.mjs --only=hero  # just matching files
```

Requires Google Chrome. On Linux, replace the `sips` call with ImageMagick (`magick in.png -quality 84 out.jpg`).

## Using real photography instead

For a real store, use your own product photos. For lifestyle or banner imagery from stock libraries:

1. **Check the license for your exact use.** The Unsplash and Pexels licenses allow commercial use on your own site without attribution, but forbid selling unaltered copies and compiling images into a competing service. Do not **redistribute** stock photos inside a theme package you sell or share. Each store should download its own.
2. Keep a record (URL, author, license, date) for every image, for example in a spreadsheet.
3. Avoid images showing identifiable people, trademarks or products you don't sell, unless you have releases and permission.

**Recommended sizes:** product images square, at least 1000×1000 (2000×2000 for zoom); hero 1600×1200; campaign and CTA banners 1800×900 with the subject on the right (text sits on the left); category images square, 800×800.

**Replacing:**

| Image | Where |
|---|---|
| Product images | Edit product → Product image / Product gallery; variation images in Variations |
| Category images | Products → Categories → edit → Thumbnail |
| Hero, campaign, final banner | Customize → Freshtastic Homepage |
| Post images | Edit post → Featured image |

Then run **Tools → Freshtastic Demo Content → Remove demo content** to delete the demo media. It only deletes images the importer created.

## Privacy

All images are served from your own media library. The theme never hot-links external image URLs, and the bundled fonts are local (no Google Fonts requests).
