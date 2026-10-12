# Responsive Audit: Desktop, Tablet and Mobile

## Method

**Crawl:** every page reachable by guest, buyer, seller and admin (73 distinct pages), opened in Chromium (Playwright 1.56) with demo data, including real orders. The crawl script is described in [ux-regression-report.md](ux-regression-report.md).

**Viewports:**

| Viewport | Represents | Input |
|---|---|---|
| 320 x 568 | Narrow phone; also 400 % zoom of a 1280 px window (WCAG 1.4.10 reflow) | Touch (`pointer: coarse`) |
| 375 x 812 | Typical phone | Touch |
| 768 x 1024 | Tablet, portrait | Touch |
| 812 x 375 | Phone in landscape (after only) | Touch |
| 1280 x 800 | Laptop | Mouse |
| 1920 x 1080 | Large desktop | Mouse |

**Colour schemes:** light at all widths; dark at 375 and 1280 px.

**What was measured on each page:**

- **Horizontal page overflow:** document wider than the viewport.
- **Elements past the right edge:** outside any scroll box.
- **Touch targets:** sizes on touch viewports, against 24 px (WCAG 2.5.8 AA) and the project's 44 px goal.
- **Errors:** console errors (CSP included) and failed requests.
- **Unnamed controls:** buttons or links without an accessible name.
- **axe-core** with WCAG 2.2 AA tags at 375 and 1280 px.
- **Text spacing (after only):** WCAG 1.4.12 at 375 px (line height 1.5, letter spacing 0.12 em, word spacing 0.16 em, paragraph spacing 2 em), looking for overflow and cut-off text.
- **Screenshots** of every page at 375 and 1280 px, reviewed by eye for the key pages:
  - catalogue, product, cart, checkout, order;
  - seller dashboard and product form;
  - admin dashboard, orders, gateway and categories.

**Interaction on a phone:** `ux.spec.js` completes a purchase at 375 px with touch:

- tapping a product card, add to cart, checkout, pay with balance;
- no sideways scrolling.

**Data:** [evidence/crawl-baseline.json](evidence/crawl-baseline.json) and [evidence/crawl-after.json](evidence/crawl-after.json).

## Results

| Check | Before (511 page views) | After (584 page views, landscape added) |
|---|---|---|
| Page overflow or elements past the edge | 0 | 0 (after fixing UX-14, a regression of this audit, and UX-11) |
| axe WCAG 2.2 AA violations | 26 (`scrollable-region-focusable` at 375 px, 13 per scheme) | 0 |
| axe target-size (2.5.8) violations | 0 | 0 |
| Touch targets below 44 px (320, 375 and 768 px) | 438 instances | 211 instances (the card overlay is not counted) |
| Console errors or failed requests (excluding the deliberate 404 page) | 0 | 0 |
| Unnamed controls | 0 | 0 |
| Text spacing: overflow or cut-off text | not measured | 0 of 73 pages |
| Catalogue, first product (375 px, screenshot) | 837 px from the top | 651 px |

## Findings and fixes

| ID | Viewport | Problem | Fix |
|---|---|---|---|
| UX-01 | 375 px and below, zoom | Wide tables scroll sideways inside a box that keyboard users could not reach | Focusable, named scroll regions |
| UX-08 | Phones | Seven filter fields in one column pushed every product below the first screen | Two columns on narrow screens; "Clear filters" |
| UX-09 | Touch | Site name 30 px high; product card titles about 21 px high | Whole card is the link; site name 44 px |
| UX-10 | Phone in landscape | Header plus a four-row section menu filled the whole first screen | Section menus scroll sideways on low screens too |
| UX-11 | 769 to about 1000 px | Fact lists in the narrow sidebar card overflowed (`/admin/gateway`) | Shrinkable label column |
| UX-14 | 320 px | Audit regression: the first grid rule squeezed staff date filters | Grid limited to the catalogue |

## Checklist of the gate

| Item | Result |
|---|---|
| Layouts and breakpoints | One layout with flexible grids and wrapping flex rows, plus breakpoints: 48 rem for two-column pages and menus; 30 rem height for landscape menus; 30 rem width for fact lists. There are no separate mobile pages. |
| Navigation on phones | Header links wrap (two to three rows on phones). Section menus become one sideways row that scrolls to the current entry. Every function stays available, nothing is hidden behind a menu button. |
| Tables and long content | Tables scroll inside their box (now with the keyboard too). IDs, addresses and URLs wrap anywhere (`overflow-wrap: anywhere`, `.mono`). |
| Dialogs and drop-downs | No modal dialogs. Confirmation panels are inline. Search suggestions sit under the field, within the header. |
| Touch targets | AA met everywhere (axe). 44 px for buttons, fields, menus, pagination, check boxes and now cards and the site name. Links in tables and lists use the AA spacing exception (UX-09). |
| Mobile forms and keyboards | `type=email`, `inputmode=decimal` for amounts, `autocomplete=one-time-code` for 2FA (with `inputmode=numeric` where only digits are allowed; the sign-in challenge also accepts letter recovery codes), and correct `autocomplete` for passwords. Fields are 100 % wide. |
| Sticky or floating parts | Only the live messages, bottom right, at most 24 rem wide and within the viewport. No fixed header. |
| Images and charts | Images keep their aspect ratio (width and height set). Charts are SVG at 100 % width, with a table next to them. |
| Orientation | Portrait and landscape both pass (UX-10 fixed). |
| Safe areas | No fixed edge elements apart from the live messages (1 rem margin), so notches cannot hide controls. Not tested on a real device. |
| Hover-only interactions | None. The page shows nothing only on hover. |
| Zoom and text size | 320 px reflow passes, which equals 400 % zoom of a 1280 px window. Text spacing passes. Sizes are in rem, so the browser text size setting scales everything. |
| Focus visibility | A 3 px focus outline at every width, in both schemes. |
| Slow connections | Busy indicator on the submitting button after 200 ms. The lab Web Vitals of the performance audit used a 9 Mbit/s, 60 ms, 4x slower CPU mobile profile: LCP under 0.5 s on the main pages. |

## Limits

- **Engine:** Chromium only, with device emulation. iOS Safari and Firefox (Android) were not run, and neither were real devices, so the on-screen keyboard behaviour and safe areas were not observed.
- **Mobile interaction:** end to end on a phone viewport only for the purchase (B5). Other journeys were checked page by page (rendering, layout and axe) on phone viewports, not run through by touch.
