# Mollie card fields unusable on the order page — two Components sets

**Date:** 2026-08-27
**Module:** `mollie-payment` (branch `main`)
**Shop:** `daniil.oxiddev.de`, Mollie the only active payment method, OPC active
**Reported as:** "mollie is the only active and i cannot pay", with a console full of
`Uncaught TypeError: Cannot read properties of undefined`

---

## 1. What the customer got

```
mollie.js:1 Uncaught TypeError: Cannot read properties of undefined (reading 'isLoaded')
mollie.js:1 Uncaught TypeError: Cannot read properties of undefined (reading 'setLabel')
mollie.js:1 Uncaught TypeError: Cannot read properties of undefined (reading 'touched')
mollie.js:1 Uncaught TypeError: Cannot read properties of undefined (reading 'setClassName')
mollie.js:1 Uncaught TypeError: Cannot read properties of undefined (reading 'blur')
mollie.js:1 Uncaught TypeError: Cannot read properties of undefined (reading 'focusInput')
```

Repeating in bursts (16×, 14×, 10× …) — one burst per interaction. The card fields
could not be filled in, so the order could not be placed.

## 2. Cause: two hosts, two sets

Two elements on the classic order page each mounted Mollie Components for the same
four fields:

| Set | Host | Visible |
|---|---|---|
| 1 | `div[data-controller="mollie-components"]` — `order.html.twig`, right column | **yes** |
| 2 | `div[data-controller="mollie-checkout-footer"]` → `#dynamic-footer-content` → `.accordion-body` — the OPC footer widget, collapsed | no |

Measured on the order page: **2 live iframes per field name** (`cardNumber-input`,
`cardHolder-input`, `expiryDate-input`, `verificationCode-input`), one set visible
in the right column and one inside the collapsed accordion. Mollie's library holds
both; interacting with the visible fields dereferences state belonging to the
other, hence the `undefined` reads on `isLoaded`, `setLabel`, `touched`,
`setClassName`, `blur`, `focusInput`.

## 3. Fix

The footer widget stands down when the order page hosts the fields
(`[data-controller~="mollie-components"]` with its `fields` target present). On the
OPC one-page checkout there is no such block, so the widget mounts exactly as
before.

Two further hardenings in `mollie_components_controller.js` — **not** the cause
here, but the same class of hazard:

- `_ensureMounted()` is async and assigned its `_mollie` guard only *after*
  awaiting Mollie.js, so two calls could both pass the guard and each mount a full
  set. The mount promise is now the guard.
- `disconnect()` unmounts the components. Without it, a host that is removed or
  replaced leaves its components registered inside Mollie's library — the same
  failure by another route.

## 4. Why the existing spec did not catch it

`mollie-standard-inline-card.spec.ts` asserts "each Components field has a single
mounted iframe" — counted **per container**. That stays 1 when the duplicate set is
mounted in a *different* container, which is exactly what happened. It passed
throughout.

New: `mollie-components-single-set.spec.ts` counts component frames **per component
type**, where a duplicate set is visible, and then types into the fields the way a
customer would, failing on any JS error. Verified both ways:

| Footer stand-down | Frames per field | Spec |
|---|---|---|
| disabled (the reported state) | 2 | **fails** |
| enabled (this fix) | 1 | **passes**, 0 JS errors |

## 5. Verification

| Gate | Result |
|---|---|
| mollie unit suite | 518 tests / 1312 assertions OK |
| E2E `mollie-components-single-set` (new) | passes; fails with the fix reverted |
| E2E `mollie-standard-inline-card` | passes |
| E2E `mollie-opc-inline-card` | passes — the footer still mounts where it is the only host |

## 6. Note for whoever meets this next

This is the second instance of one pattern in two days: a PSP library that allows
one instance per page, and two hosts on the order page each mounting it. Stripe had
it with Embedded Checkout (see the stripe dev log for 2026-08-26, reports 04 §8).
Both fixes are the same shape — **the order page owns the payment UI, the OPC
footer widget stands down there** — and in both cases the test that should have
caught it was counting per container instead of per provider component.

## 7. Commits

| Commit | Subject |
|---|---|
| `3c118fb` | fix(components): one Mollie Components set per page — card fields were unusable |
