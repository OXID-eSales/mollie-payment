# 2026-08-27 — Mollie

## Done

- [Duplicate Components sets on the order page](reports/01-duplicate-components-sets.md) —
  two hosts (order-page block + OPC footer widget) each mounted the four card fields, so
  Mollie's library threw `Cannot read properties of undefined` on every focus/keystroke and
  the card could not be filled in. Footer widget now stands down when the order page hosts
  the fields; plus a re-entrancy guard and `disconnect()` teardown in the components
  controller. Commit `3c118fb`.

## Open

- The existing `mollie-standard-inline-card` spec counts iframes per container and cannot
  see a duplicate set mounted elsewhere; the new `mollie-components-single-set` spec counts
  per component type. Worth folding the per-type check into the older spec too.
