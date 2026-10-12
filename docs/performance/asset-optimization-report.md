# Images, Static Assets and Network Transfer

All measurements are lab measurements:

- **Chromium:** `tests/performance/webvitals.mjs`, mobile profile, cold cache, median of 5 loads.
- **Server:** the page benchmark.

Evidence files:

- [evidence/webvitals-baseline.json](evidence/webvitals-baseline.json)
- [evidence/webvitals-after.json](evidence/webvitals-after.json)
- [evidence/bench-after.json](evidence/bench-after.json)

## Static assets

| Asset | Raw | gzip (nginx, level 6) | Caching |
|---|---|---|---|
| `public/css/app.css` | 17,914 B | 4,741 B | `?v=<content hash>`: `public, max-age=31536000, immutable`; without the hash, 1 hour |
| `public/js/enhance.js` | 20,471 B | 5,601 B | Same; loaded with `defer`; the shop works without it |

- **Fonts:** none (system font stack), so no font files, subsetting or font-swap shifts.
- **Third-party scripts:** none.
- **Content Security Policy:** blocks every external origin.
- **Bundling:** no bundler, frameworks or source maps; nothing can be tree-shaken or split, and nothing debug-only is shipped.
- **Budgets** (`tests/performance/budgets.json`): CSS 25,000 B raw / 6,500 B gzip, JavaScript 30,000 / 8,000. Both pass; checked by the `assets` gate check in CI.
- **Compression** (nginx, verified with curl): `Content-Encoding: gzip` and `Vary: Accept-Encoding` for CSS, JavaScript and SVG. `gzip_min_length 512` skips tiny files, and already compressed formats (WebP, ICO) are left alone.
- **Brotli:** not available in the stock nginx image. gzip saves 73-74% on these files; Brotli would save a few hundred more bytes and needs a custom nginx build. Not justified.

## HTML

| Page | Before | After | gzip size (not sent, see below) |
|---|---|---|---|
| Catalogue | 41,963 B | 19,126 B | about 5 KB |
| Search | 41,814 B | 18,042 B | - |
| Catalogue page 50 | 41,717 B | 16,993 B | - |

**What changed (PERF-05):** the seller filter had 426 options (28 KB) and now lists the top 50 plus the selected seller. DOM nodes on the catalogue fell from 647 to 272, and lab TBT from 127 to 64 ms.

**Compression stays off for HTML (PERF-22).** Pages carry the CSRF token next to reflected input, the BREACH setting. HTML responses are `Cache-Control: no-cache, private`; they hold per-user data and are never shared-cached.

**Sitemap (PERF-06):** 2,073,357 B -> 154,262 B gzip, cached for an hour.

## Product images

**Pipeline** (`ProductImageService`):

- Uploads are decoded by GD (limit 25 megapixels) and re-encoded to WebP at quality 82, in two sizes: 1600 px "large" and 480 px "thumb".
- Originals and EXIF are dropped.
- Variants are generated once at upload, never per request.

**Formats:**

- WebP covers every current browser.
- AVIF would save more bytes but needs libavif in the image's GD build, and encodes several times slower on upload. Not justified at these sizes.

**Markup:**

| Where | Image | Size hints and loading |
|---|---|---|
| Product cards | 480 px thumb | `width` and `height` from the stored aspect ratio, so no layout shift (CLS 0 on catalogue, search and home); lazy-loaded except the first two cards, which load eagerly with `fetchpriority="high"` (PERF-20) |
| Product page | Main image | `srcset` (480w and 1600w) with `sizes="(max-width: 48rem) calc(100vw - 2rem), 44rem"` and dimensions |
| Product page | Additional images | Thumbs, lazy-loaded |

**Lab result after the change:**

- Catalogue: LCP 592 -> 436 ms. Search: 724 -> 424 ms. Both pages had their lazy-loaded LCP image fixed, and their HTML shrank.
- No image in the viewport is lazy-loaded any more (`images_above_fold_lazy` 1 -> 0).
- Product page LCP is 448 ms, with CLS 0.026 in both runs. The shift comes from text, not the image, which has dimensions.

**Serving (PERF-07):**

- Listed products' images are served without a session or cookies, with `Cache-Control: public, max-age=31536000, immutable`, so browsers and any CDN keep them.
- Unlisted products' images (seller and staff previews) are `private, no-store` on a separate route.
- Each image request still boots Laravel to check that the product is listed: 3 queries.

**Not changed (Low):**

- Product cards use one 480 px thumb without `srcset`. On a 412 px phone at DPR 2 the card is about 380 CSS px wide, so 480 px is under the ideal 760 px; quality is acceptable for a card. A 960 px variant would add bytes on desktop.
- SVG uploads are not accepted (JPEG, PNG, WebP only), so untrusted SVG cannot reach users.

## Transfer per page (lab, cold cache, mobile)

| Page | Requests | Bytes before | Bytes after |
|---|---|---|---|
| Catalogue | 8 | 91,587 | 65,031 |
| Search | 9 | 92,186 | 65,626 |
| Product | 5 | 81,842 | 81,377 |
| Home | 9 | 22,351 (no images loaded in that run) | 56,383 (6 card images) |

## Budgets

| Check | Result |
|---|---|
| Asset size budgets (CSS, JavaScript) | Pass |
| Lab Web Vitals budgets (LCP 2,500 ms, CLS 0.1, TBT 200 ms, INP 200 ms) | Pass on home, catalogue, search and product, before and after |

The Web Vitals figures are lab values on one profile, not 75th-percentile field data. Real-user measurement after launch is listed as not verified in the summary.
