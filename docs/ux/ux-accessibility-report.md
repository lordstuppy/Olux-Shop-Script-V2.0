# Accessibility Report

**Target:** WCAG 2.2 level AA.

**Status:** automated checks pass and scripted keyboard checks pass. **Screen-reader testing was not possible in this environment**, so conformance is not claimed (gate U8: BLOCKED).

## What was run

| Check | Scope | Result |
|---|---|---|
| axe-core 4.10, tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa` (CI: `a11y.spec.js`) | 64 page visits (guest 12, buyer 12, seller 10, admin 30), in 3 projects: light 1280 px, dark 1280 px, and phone (375 px, touch) | 12 / 12 tests pass. Before the audit the suite ran without `wcag22aa` and without the phone project. |
| The same axe scan in the audit crawl | 73 pages x 375 and 1280 px x light and dark | 26 violations before (UX-01), 0 after |
| axe best-practice rules | Same | 0 before and after |
| Structure (CI) | Same pages | Exactly one `h1`; no skipped heading levels; every visible field labelled; no positive `tabindex`; the skip link is the first Tab stop |
| Keyboard journey (CI: `ux.spec.js`) | Sign in, add to cart, checkout (radio group with arrow keys), pay | Completed with Tab, Enter, Space and arrow keys only |
| Keyboard: search suggestions, confirmation panels, password toggle | `ux.spec.js`, audit script | Down arrow into the list, Escape back to the field; `<summary>` opens and closes with Enter and Space; toggle with Enter and Space keeps the value and sets `aria-pressed` and the label |
| Focus management | `ux.spec.js` | Error summary focused after a failed submission (UX-07); Escape returns focus to the search field |
| Text spacing (1.4.12) | 73 pages at 375 px | No overflow, nothing cut off |
| Reflow (1.4.10) | 73 pages at 320 px | No page overflow; tables scroll inside focusable regions |
| Contrast (1.4.3, 1.4.11) | axe in light and dark, with confirmation panels opened | 0 violations |

## WCAG 2.2 criteria with specific attention

| Criterion | Level | Result and evidence |
|---|---|---|
| 1.1.1 Non-text content | A | Product images use the seller's alt text or are marked decorative. The SVG eye icon is `aria-hidden`, and the button has a label. Charts come with a data table. |
| 1.3.1 Info and relationships | A | Real tables with `scope`, captions where useful; fieldsets for radio groups; `aria-describedby` from fields to hints and errors. |
| 1.4.1 Use of colour | A | Statuses carry text labels ("Paid", "Pending review"). Unread notifications say "Unread:" to screen readers, besides the coloured border. |
| 1.4.10 Reflow; 1.4.12 Text spacing | AA | Pass (see above). |
| 1.4.13 Content on hover or focus | AA | Search suggestions can be dismissed (Escape) and stay while hovered. There are no tooltips. |
| 2.1.1 Keyboard | A | **Was failing on phones (UX-01), fixed.** The whole purchase journey works by keyboard. |
| 2.2.1 Timing adjustable | A | **No warning before the 120-minute idle session limit (UX-22).** Now warned 2 minutes ahead, with "Stay signed in" (with JavaScript). Toasts that close by themselves (4 to 10 s) repeat information that stays available in the notification centre or on the page. |
| 2.4.3 Focus order | A | Follows the document; no positive `tabindex`. Focus moves only on a failed submission (to the error summary) and on Escape in the search. |
| 2.4.7 Focus visible; 2.4.11 Focus not obscured | AA | A 3 px focus outline in both schemes. No sticky header. The live messages (bottom right, at most 24 rem) can overlap part of a focused element but never all of it. |
| 2.5.7 Dragging movements | AA | No dragging anywhere. |
| 2.5.8 Target size (minimum) | AA | axe `target-size`: 0 violations at 375 and 1280 px. On touch screens 44 px for buttons, fields, menus and cards (UX-09). |
| 3.2.6 Consistent help | A | Support email in the footer of every page; support tickets in the main navigation when signed in. |
| 3.3.1 Error identification; 3.3.3 Error suggestion | A, AA | Field-level messages naming the fix, an error summary, and `aria-invalid`. Input is kept after errors (passwords excepted). |
| 3.3.4 Error prevention (legal, financial, data) | AA | Checkout shows totals and requires accepting the terms. Irreversible actions have a confirmation step (UX-02). Payments are idempotent. |
| 3.3.7 Redundant entry | A | Nothing is asked twice within a process. After a session end, typed text can be kept (UX-03). |
| 3.3.8 Accessible authentication (minimum) | AA | No cognitive test (no CAPTCHA; a honeypot and a timing check instead). Password managers and pasting work (`autocomplete` set). 2FA codes can be pasted. Show/hide password. |
| 4.1.2 Name, role, value | A | No unnamed controls (crawl). Toggle with `aria-pressed`. Scroll regions named after their table. |
| 4.1.3 Status messages | AA | Flash messages are `role=status` or `role=alert`. Live messages sit in one polite `role=status` region. The suggestion count is announced. Routine background refreshes are not announced, except one short "This page was updated." |

**Also checked:**

- **Reduced motion:** the busy spinner stops when `prefers-reduced-motion` is set. There are no other animations.
- **Dialogs:** there are no modal dialogs, so no focus trap is needed. The confirmations are inline disclosure panels.

## Not performed, and why

| Check | Why not | What it means |
|---|---|---|
| Screen readers (NVDA or JAWS with Windows browsers, VoiceOver on macOS and iOS, TalkBack) | Not available in this Linux container | Names, roles, states and live regions were verified through axe and Playwright role locators, which read the browser's accessibility tree. Actual announcements, their wording and timing, and the reading order through a screen reader were not heard. |
| Real users with disabilities | No participants | - |
| Windows high-contrast or forced colours | Not available | The design uses borders on buttons and status badges, which survive forced colours, but this was not checked. |
| Browser zoom in Safari or Firefox | Chromium only | Reflow was tested by viewport width, which is equivalent in Chromium. |

## To reach PASS

1. Run the [screen-reader script](#screen-reader-script) with NVDA + Firefox and VoiceOver + Safari (iOS). Record the results.
2. Fix what it finds and keep `a11y.spec.js` (all three projects) green in CI.

### Screen-reader script

1. **Sign in:** use a wrong password first. The error summary should be read on load.
2. **Search:** type "pho". The suggestion count should be announced. Use the down arrow, then Enter.
3. **Buy:** product page (images and price read), add to cart (announced), checkout (the radio group, the disabled balance option with its reason), pay.
4. **Order page:** wait for a live update. "This page was updated." should be read once, the new content not re-read entirely, and focus left in place.
5. **Ticket:** write a reply while staff answer. "This page has new information" should be read, with the "Show it" button reachable.
6. **Session end:** sign out in another tab. The warning and its link should be read.
7. **Seller:** "Stop delivering" should read as a collapsed control. Opening it should read the consequence; then the confirm button.
8. **Staff:** a wide table on a phone should be reached as a named region and scrolled with the arrow keys.
