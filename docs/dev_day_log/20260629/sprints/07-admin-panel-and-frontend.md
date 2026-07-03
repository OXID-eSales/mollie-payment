# Sprint 7 — Admin panel & frontend

**Goal:** Render Mollie into the shared admin "Payment" tab (transaction history + capture/refund/
cancel forms with validation) and ship the storefront method selector + redirect JS.
**Definition of Done (sprint-level):** Admin order detail shows a Mollie panel with live transaction
history and working action forms; storefront shows selectable Mollie methods and drives the redirect flow.

## Out of scope
- Embedded card components (`@mollie/components`) / 3DS-on-page — Sprint 8 stretch (redirect is enough).
- New admin menu entries — the tab is shared, owned by payment-base (menu.xml stays empty).

## Risks & unknowns
- **Admin controllers have no constructor DI.** Use the testable-subclass pattern (protected
  `getViewDataProvider()` seam) — established by Stripe/PayPal.
- **OXID 7.4 admin Twig block uncertainty.** Don't rely on `oxtplblocks` for the admin tab; render
  via the panel-provider into the shared tab (the way Stripe/PayPal do post "Sprint I").

---

## Story 1 — MolliePaymentPanelProvider (oe.payment.admin_panel)

**Why:** payment-base owns the shared Payment tab; the provider injects Mollie's view model.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Admin/MolliePaymentPanelProviderTest.php`
  - `testProvide_ReturnsPanelForMollieOrder`
  - `testProvide_ForNonMollieOrder_ReturnsNull`

**Implementation steps:**
1. `Admin/MolliePaymentPanelProvider.php` tagged `oe.payment.admin_panel`, public.
2. Aggregate from a view-data builder (Story 2) + order loader; protected seams for tests.

**SOLID/Clean check:** SRP: assemble panel view model. DIP: depends on builder/loader interfaces.
**DevOps gate:** `phpstan` ✓ · Unit ✓ · Integration (registry pickup) ✓.
**Definition of Done:** Mollie orders get a panel in the shared tab; non-Mollie orders are untouched.

---

## Story 2 — TransactionHistoryService (Mollie API as source of truth) + view-data builder

**Why:** Display must reflect dashboard actions too, so read history from the Mollie API (parity with
Stripe's "display from API, audit from DB" strategy).
**Estimate:** L

**Tests first (TDD):**
- `tests/Unit/Service/TransactionHistoryServiceTest.php`
  - `testHistory_ListsPaymentRefundsAndCaptures`
  - `testHistory_MapsStatusesToBadges`
- `tests/Unit/Admin/MolliePanelViewDataBuilderTest.php`
  - `testBuild_IncludesCaptureAndRefundBounds`

**Implementation steps:**
1. `Service/TransactionHistoryService.php` — fetch payment + refunds + captures via adapter; map to rows.
2. `Admin/MolliePanelViewDataBuilder.php` — rows + bounds (from Sprint 6 `AdminActionBounds`) + ids.
3. `Service/MollieUrlBuilder.php` — Mollie dashboard deep-links per transaction.

**SOLID/Clean check:** SRP: history vs builder split. DRY: badge mapping via `MollieStatusMapper`. No-else.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓.
**Definition of Done:** Panel shows fresh API-sourced history with correct bounds and dashboard links.

---

## Story 3 — Admin amount validation + feedback

**Why:** Reject impossible capture/refund amounts before dispatching the action; surface clear errors.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Admin/AdminAmountValidatorTest.php`
  - `testRefund_ExceedingRefundable_Fails`
  - `testCapture_ExceedingAuthorized_Fails`
  - `testAmount_NonNumericOrNegative_Fails`

**Implementation steps:**
1. `Admin/AdminAmountValidator.php` + `Admin/AdminValidationFeedback.php` (session-backed messages).
2. Reuse the bounds object from Sprint 6.
3. Reuse the **payment-base validation message formatter** (`oe.payment_base.validation_message_formatter`,
   tagged in Sprint 4 Story 6) for consistent error rendering — do not add a second formatter path.

**SOLID/Clean check:** SRP: semantic amount validity (numeric/bounds), distinct from Sprint 4's
character-level user-data validation — same payment-base message-formatter plumbing, different rules. DRY: bounds + formatter reused, not recomputed. No-else.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** Bad amounts are blocked with a readable admin message; valid ones dispatch.

---

## Story 4 — Admin panel Twig + action forms

**Why:** The operator-facing surface for history + capture/refund/cancel.
**Estimate:** M

**Tests first (TDD):**
- (Template render covered via panel-provider unit tests + a thin controller test.)
- `tests/Unit/Controller/Admin/OrderActionDispatchTest.php`
  - `testRefundFormPost_DispatchesRefundRequestEvent`

**Implementation steps:**
1. `views/twig/admin/panel/mollie_panel.html.twig` (registered in metadata templates).
2. Capture/refund/cancel forms posting to the `OrderActionDispatcher` (Sprint 6).
3. Admin JS (esbuild `mollie-admin.min.js`) for client-side amount hints — minimal.

**SOLID/Clean check:** SRP: form posts -> dispatcher. No-else: guard on missing contract.
**DevOps gate:** `phpstan` ✓ · `phpcs` ✓ · Unit ✓.
**Definition of Done:** Operator can submit capture/refund/cancel from the tab; actions reach Sprint 6 services.

---

## Story 5 — Storefront method selector + redirect JS (esbuild)

**Why:** Customers must pick a Mollie method (iDEAL, cards, etc.) and be redirected; this is the only
provider-specific storefront surface (Stripe Checkout hosts its own picker; Mollie does not pre-redirect).
**Estimate:** L

**Tests first (TDD):**
- `tests/Unit/Service/PaymentMethodListServiceTest.php`
  - `testList_ReturnsEnabledMethodsForCurrencyAndCountry`
- Frontend: `resources/.../mollie_checkout_controller.spec.ts`
  - `submitsOrderAndFollowsRedirect`

**Implementation steps:**
1. `Service/PaymentMethodListService.php` -> Mollie Methods API (cache per request); filter by
   currency/country config.
2. `views/twig/frontend/mollie_methods.html.twig` selector; Stimulus
   `resources/js/controllers/mollie_checkout_controller.js`.
3. esbuild config + `package.json` build scripts; output `assets/js/mollie-frontend.min.js`.
4. Logging: route any `console.*` through a `debug()` wrapper gated by log level (Stripe Phase-5 lesson).

**SOLID/Clean check:** SRP: method-list service vs controller. DRY: amount/currency via existing DTO/config.
**DevOps gate:** `phpstan` ✓ · `phpcs` ✓ · Unit ✓ · `npm run build` clean · `./bin/pre-commit-check.sh --full` ✓.
**Definition of Done:** Storefront lists valid methods; selecting one + Place Order redirects to Mollie;
prod JS bundle ships no stray `console.log`.

---

## Suggested order
1. Story 2 (history service + builder) — the data the panel needs.
2. Story 1 (panel provider) — registers into the shared tab.
3. Story 3 (validation) — guards the actions.
4. Story 4 (admin twig/forms) — operator surface.
5. Story 5 (storefront + JS) — customer surface; largest, do last.
