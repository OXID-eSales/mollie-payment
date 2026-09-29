# Check — "I cannot enter into the credit card fields" (standard checkout, AGB on, card) — 2026-09-29

Asked after MOL-9 landed on the branch. Method: the inline-card e2e spec, then Playwright scripts that drive the
order step as a shopper in Chromium and Firefox against the tunnel (bundle md5 on the tunnel == local build).

## What holds

| Scenario | Chromium | Firefox 151 (Playwright) |
|---|---|---|
| `InlineCardComponents` spec (tick AGB → pick Card → fill → tokenize → 3DS → order) | green | — |
| Block locked at load, tick AGB, pick Card, type in all four fields | all four filled | all four filled |
| Card iframes mounted **while** the block was locked (card preselected), then tick AGB, type | all four filled | all four filled (first field needs a 2nd click, see below) |
| Lock → unlock → lock again with mounted iframes; typing while locked | nothing lands (as intended) | nothing lands |
| `inert` supported | yes | yes |

The AGB lock does not break card entry: once the box is ticked the fields take input in both engines, whether
the iframes were mounted before or during the lock.

## What can look like "cannot enter"

1. **Typing before ticking the AGB box** — that is the MOL-9 lock itself. The region is dimmed to 50 % and
   `inert`, but nothing on the block says *why*; the AGB box sits in the sidebar form above. OPC's section lock
   shows a short hint on its veil for exactly this reason.
2. **First click into a Mollie iframe in Firefox** — the iframe receives focus (`activeElement` is the iframe)
   but the input inside does not take the keystrokes; the **second** click works. Reproduced with and without
   any lock (`free-mount` vs `locked-mount`), so pre-existing and not MOL-9. Chromium takes the first click.
3. **Dead zone in the field box** — Mollie's iframe is 18 px tall inside the 42 px `.mollie-field` box
   (`padding-top: .55rem`). A click on the lower ~10 px or upper ~13 px of the box hits the box, not the iframe,
   and nothing focuses. Pre-existing (`.mollie-field` CSS unchanged by MOL-11/9). Measured: box y 500–542,
   iframe y 514–532; a click at the box bottom typed nothing, a click on the iframe typed "Muster".

## Proposed follow-up (not applied — awaiting the word)

- Locked-state hint inside the region (e.g. `MOLLIE_ACCEPT_AGB_FIRST`: "Please accept the terms and
  conditions first."), toggled by `mollie-agb-gate` with the lock — makes 1. self-explanatory.
- Field box focus: forward a click on `.mollie-field` to the component (`component.focus()`), and/or let the
  iframe fill the box (`.mollie-field iframe { height: 100% }` with a fixed box height) — removes 3.
- 2. is Mollie's iframe behaviour in Firefox; a `component.focus()` on the container click may also cover it —
  to be measured.
