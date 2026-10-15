# Sprint 01: in3 offered consistently when (and only when) the shopper is eligible

**Date:** 2026-09-25
**Ticket:** id tbd (in3 / Installments inconsistently available)
**Branch (proposed):** `b-7.4.x-in3-method-availability` off `b-7.4.x` in **mollie-payment**
**Status:** PLANNED — awaiting approval. Nothing implemented, nothing committed.
Investigation: `../reports/01-in3-method-availability.md`.

## Problem (ticket)

With Mollie enabled and in3 activated on the profile, the in3 tile shows up in some checkout
sessions and not in others for the same basket. Expected: in3 is consistently offered when its
eligibility conditions are met.

## What the investigation found (read before executing)

1. **The only per-session input to Mollie's `/methods` call is the session user's billing
   country** (`ViewConfig::mollieBillingCountryIso()`). No user / no country → no filter → Mollie
   returns every activated method, in3 included. DE/AT/BE country → Mollie removes in3. On this
   shop 0 accounts are Dutch, 397 are German. Guest one-page-checkout sessions whose footer was
   rendered before the address existed see in3; logged-in customers never do.
2. **No amount is sent.** Mollie's real in3 window on this profile is 50.00–5000.00 EUR (probed),
   but the list ignores the basket total, so in3 is offered for baskets that Mollie will refuse.
3. **Failures are silent.** `ViewConfig::getMollieMethods()` returns `[]` on any `Throwable`
   without a log line; the page falls back to the classic redirect button. A transient Mollie
   error looks exactly like "in3 missing".
4. Mollie hiding in3 for a non-NL billing address is correct. The defect is that the module's
   inputs to that decision differ per session and are never observable.

## Approach

Make the method list a pure function of the shopper's real eligibility inputs, and make it
observable:

- pass **amount + currency** (basket total) alongside `billingCountry`;
- resolve the **billing country from the address the shopper entered**: session user first; if
  there is none, the country the OPC form currently holds (passed by OPC when it renders the
  footer) and otherwise the shop's default country — never "no filter";
- **log** every `/methods` call (country, amount, returned ids) at `normal` level and every
  swallowed exception at `errors` level through the module's existing logger factory;
- **rebuild the OPC footer** after the address is saved/changed, so the tile list follows the
  address (OPC already re-renders the footer widget on payment (re)selection — Story 4 verifies
  whether it does so on address change and adds the hook if not).

**Definition of Done (sprint-level):** with inline components on, a DE-billing shopper never sees
in3, an NL-billing shopper with a 50–5000 EUR basket always sees it, an NL shopper with a 30 EUR
basket does not; the OPC footer shows the same list as the standard order page for the same
address; a forced `/methods` failure leaves a log line naming the exception; gates green
(`composer phpcs` in the CI form with warnings, phpstan, phpmd, unit, `mollie-standard` +
`mollie-opc` e2e regressions).

| Principle | Application |
|---|---|
| TDD-first | Red e2e matrix (Story 1) and red unit tests (Stories 2, 3) precede every production edit |
| SRP | `MethodsListRequest` carries the inputs; `PaymentMethodListService` decides what to ask; `ViewConfig` only gathers shop state; a small `CheckoutCountryResolver` owns the country fallback chain |
| DIP | Resolver injected into the list path via the container; ViewConfig keeps its seam methods `protected` for tests |
| DRY | One list builder for the order page and the OPC footer (already shared via `ViewConfig::getMollieMethods()`); no second code path |
| Clean Code | No `else`; methods ≤ 25 lines; no silent `catch (Throwable) { return []; }` without a log |
| No overengineering | No per-method admin switches, no amount-range table in the module (Mollie owns eligibility), no session cache |
| DevOps-first | `./bin/pre-commit-check.sh` + `composer phpcs` (CI form) before each commit; CHANGELOG entry; push, CI green, no merge without approval |

## Stories

### Story 1 — Red proof: eligibility matrix e2e + unit reds
- `tests/e2e/playwright/tests/MollieStandard/In3MethodAvailability.spec.ts` (inline mode):
  1. DE billing, 100 EUR basket → in3 **not** listed (currently green — regression guard);
  2. NL billing, 100 EUR basket → in3 listed (needs an NL address on the e2e user for the run —
     NL must be an active `oxcountry`; verify, else activate for the run and restore);
  3. NL billing, 30 EUR basket → in3 **not** listed (currently **red**: no amount is sent).
- `tests/e2e/playwright/tests/MollieOpc/In3FooterFollowsAddress.spec.ts`: guest OPC session,
  footer rendered, then a DE address saved → in3 must not be in the footer list (expected
  **red**: list built without a user, not rebuilt).
- Unit reds: `MollieMethodsFetcherTest` asserts `amount[value]`/`amount[currency]` in the params;
  `PaymentMethodListServiceTest` asserts the cache key includes the amount.
- DoD: reds recorded with output in `../done/01-in3-method-availability.md`.

### Story 2 — Send amount + currency to `/methods`
- `MethodsListRequest` gains `?MollieAmount $amount` (value + currency); `MollieMethodsFetcher`
  maps it to `amount[value]` / `amount[currency]`; `PaymentMethodListService::listActiveMethods()`
  takes the amount and includes it in the cache key; `ViewConfig::getMollieMethods()` passes the
  basket total (`Registry::getSession()->getBasket()->getPrice()->getBruttoPrice()`, formatted
  with two decimals) via a `protected mollieBasketAmount()` seam.
- Unit tests from Story 1 go green; existing tests updated (constructor signature).
- DoD: matrix case 3 green.

### Story 3 — Deterministic billing country + logging
- `Service\CheckoutCountryResolver`: session user country → OPC-supplied country (request
  parameter the footer render receives from OPC, if present) → shop default country
  (`sDefaultCountry` / first active country). Returns ISO-2 or throws; never `null`.
- `ViewConfig::getMollieMethods()`: log the inputs and returned method ids at `normal`, log the
  caught `Throwable` at `errors` (module logger factory), still return `[]` so the redirect fallback
  keeps working.
- Unit tests for the resolver chain and for "exception is logged".
- DoD: a forced `/methods` failure (bad key in a test) produces one log line naming the exception.

### Story 4 — OPC footer follows the address
- Verify in `one-page-checkout` whether the footer widget is re-rendered after the address step
  saves the user. If yes: Story 1's OPC spec goes green with Story 3 alone. If no: pass the
  entered country to the footer render (OPC → Mollie footer) or trigger a footer re-render on
  address save — whichever OPC already supports; if OPC needs a change, that is a separate
  one-page-checkout story and the Mollie spec stays red until it lands (report it, do not fork OPC).
- DoD: OPC spec green, or the OPC gap documented with the exact hook missing.

### Story 5 — Regression, changelog, docs
- `mollie-standard` and `mollie-opc` Playwright projects green (Klarna specs env-gated skips
  allowed); gates green; `CHANGELOG.md` `[Unreleased]/Fixed`; `done/01-…md` + this file updated;
  push branch, CI green, **no merge without approval**.

## Out of scope
- Hiding in3 for German shoppers — that is Mollie's correct eligibility answer, not a defect.
- Per-method admin switches or a module-side amount table for in3/Klarna/Riverty/Billie.
- Sending the *delivery* address to Mollie (`shippingAddress` is always the billing address
  today) — separate ticket if wanted.
- Changes inside `one-page-checkout` beyond what Story 4 identifies as the minimal hook.
- Stripe/PayPal method lists (they have no Mollie-style methods API).

## Risks / open questions
- NL must be an active country in the shop for the NL e2e cases; the run activates it if needed
  and restores the flag afterwards.
- Mollie returns the amount-filtered list only when the amount is given; a basket total that
  changes after the footer rendered (voucher, shipping) is a known residual until the footer is
  re-rendered on basket change (OPC concern, not planned here).
