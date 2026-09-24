# Sprint MOL-15: User-data validation in Mollie, exactly as in Stripe

**Date:** 2026-09-24
**Ticket:** MOL-15
**Branch:** `b-7.4.x-MOL-15-user-data-validation-parity` (mollie-payment only — payment-base already owns the
framework and needs no change; Stripe is untouched).
**Status:** PLANNED — implementation follows in this branch; merge only on the product owner's approval.
**Definition of Done (sprint-level):** every user/address field Mollie sends to the PSP runs through
payment-base's `ValidationBase` (module id `oe_payments_mollie`) at the same three boundaries Stripe guards —
the classic order step (server side), the OPC footer (live, via `cl=oepaymentvalidationapi`) and the admin
free-text inputs — with the same field set, the same rules, the same delivery-address pass and the same
per-field translated messages ("The <field> field is not valid. Allowed symbols are: …"). No code-level
reference between mollie-payment and stripe in either direction.

## Where Mollie stands today (from the read-only comparison, 2026-09-24)

Mollie already ships the framework's *shape*: `src/Resources/validation-rules.php`, `Service\UserDataValidator`
(factory-built, `oe_payments_mollie`), `OxidUserFieldReader`, `UserDataValidationMessageFormatter` tagged
`oe.payment_base.validation_message_formatter`, and a payment-step gate in `Controller\PaymentController`.
What is missing or different versus Stripe:

| # | Stripe has | Mollie | Story |
|---|---|---|---|
| 1 | Billing **and delivery** address pass (`hasDeliveryAddress()` / `readDeliveryField()`, `addressKind` on the failure) | billing only | 2 |
| 2 | Fields `postalCode`, `cellPhone`, `personalPhone`, `fax` | `zip`; no phone trio | 2 |
| 3 | `AllowedSymbolsDescriber` (class token → words) built by `ValidationRulesProvider::createDescriber()`, reused by formatter **and** widget | describer logic buried in the formatter | 3 |
| 4 | Translations `*_VALIDATION_FIELD_INVALID`, `_LABEL_<field>`, `_CLASS_*`, `_REVIEW_ADDRESS`, `_UNDERSTAND` (storefront + admin) | **none** — every `MOLLIE_VALIDATION_*` key renders raw, incl. `MOLLIE_VALIDATION_INVALID_USER_DATA` | 3 |
| 5 | Order-step gate before the PSP is called, per-field messages | payment step only, one generic key; `MollieOrderController::execute()` dispatches unvalidated | 4 |
| 6 | OPC footer exposes `validationUrl` / `pluginModuleId` / `fieldAllowed`; a Stimulus validator POSTs the live fields to the central endpoint before submit, marks fields `.is-invalid`, emits `oe:payment:error` | footer posts straight to `processCheckout` | 5 |
| 7 | Admin capture reason / refund description validated (`validateFieldMap(…, 'admin')`) and formatted with the tagged formatter | amounts only; free text passes through | 6 |
| 8 | Tests: delivery pass, controller gate, widget data, describer, admin text, e2e | thinner (Mollie has an extra endpoint guard-parity test) | all |

Not copied (Stripe-specific): Stripe's AJAX `createCheckoutSession` 422 flow (Mollie posts a plain form to
`cl=order&fnc=execute`, so its gate is server-side rendering), the `stripe-*` DOM ids/keys, the OPC-192
remount logic, the unused legacy `*_GENERIC` / `*_BLOCKED_CHARACTER` keys.

## Approach

| Principle | Application |
|---|---|
| TDD-first | every story opens with red unit tests; Story 1 lands the red e2e for the classic flow |
| Fix at the seam | validation stays in payment-base; Mollie contributes rules, reader, formatter, and three call sites |
| SRP | describer, reader, validator, formatter, gate each one job; `execute()` stays orchestration |
| ISP / DIP | `UserFieldReaderInterface` grows to Stripe's three methods (≤5); controllers depend on `UserDataValidatorInterface` |
| DRY | one rules file, one describer, one formatter used by storefront, OPC and admin; shared translations for labels |
| No overengineering | no new endpoint, no config switch, no payment-base change; Mollie-local copies of the tiny describer/translator (lifting them into payment-base would touch Stripe — follow-up) |
| Independence | no `Stripe` symbol in Mollie; a regression test greps `src/` for `OxidEsales\Payments\Stripe` / `oe_payments_stripe` |
| DevOps-first | phpcs / phpstan max / phpmd (controller stays ≤ 25 methods, complexity < 50) / Unit; standalone `tests/phpunit-unit.xml` suite; `mollie-standard` e2e |

## Decisions (defaults; approver may override inline)

- **Logical field names follow Stripe** (`postalCode`, not `zip`) so labels, widget field map and rules read
  the same across modules. Mollie keeps its extra `email` field (Mollie receives the e-mail; Stripe does not).
- **Classic-flow failure UX:** `execute()` shows one `MOLLIE_VALIDATION_FIELD_INVALID` message per failing field
  plus `MOLLIE_VALIDATION_REVIEW_ADDRESS`, and returns to the **address step** (`user`) so the shopper can fix
  it; nothing is dispatched, no contract, no order. The payment-step gate stays and uses the same messages.
- **Fail-open** when the validator cannot be resolved (as today, with the logged warning) — defence in depth
  must not take checkout down.
- **OPC validator fails open on non-OK endpoint responses** (as Stripe's does) and blocks only on
  `{valid:false}`.
- **Admin free text:** `captureReason` and `refundDescription` are validated with the same rules Stripe uses
  for those fields (letters, digits, spaces, common punctuation), addressKind `admin`.

## Out of scope

- Moving `AllowedSymbolsDescriber` / `LanguageTranslator` into payment-base (would require a Stripe change).
- Per-PSP rate-limit override (neither module has one).
- Wiring payment-base's `iValidationApiRatePerMinute` setting (payment-base TODO).
- Validating fields Mollie never sends (e.g. `vatId` beyond what the rules file already lists stays as is).

---

## Story 1 — Prove it: red unit tests for the gaps, red e2e for the classic flow

**Estimate:** S

**Tests first:**
- `tests/Unit/Service/UserDataValidatorTest.php`: `testValidatesTheDeliveryAddressWhenOneIsSelected`,
  `testReportsTheAddressKindOnAFailure`, `testKnowsThePhoneTrioAndPostalCode` — RED (interface has no delivery
  methods, failure has no `addressKind`, fields unknown).
- `tests/Unit/Controller/MollieOrderControllerTest.php`:
  `testExecuteWhenUserDataInvalidShowsFieldMessagesReturnsToAddressStepAndDispatchesNothing` — RED.
- e2e `tests/e2e/playwright/tests/MollieStandard/UserDataValidationBlocksCheckout.spec.ts`: change the
  storefront billing street to `Hugo-Junkers Str: <script>` through `cl=user` (OXID core accepts it), reach the
  order step, click "Order now" → expect the translated per-field message ("street", allowed symbols) and the
  review notice, expect **no** redirect to Mollie and **no** new order (DB helper), then repair the street and
  pay. RED today: the payment step already blocks, but with the raw key `MOLLIE_VALIDATION_INVALID_USER_DATA`.
  The spec restores the address in `finally`.
- `tests/Unit/Security/ModuleIndependenceTest.php` (new, green from the start, pins the rule): no
  `Stripe` namespace / module id string under `src/`.

**Definition of Done:** the three red tests fail for the gap reasons above; independence test green.

## Story 2 — Field and reader parity: delivery pass, `addressKind`, `postalCode`, phone trio

**Estimate:** M

**Tests first:** Story 1's validator tests; `OxidUserFieldReaderTest` (new): billing map incl. `oxprivfon` /
`oxmobfon` / `oxfax` / `oxzip` → `postalCode`, `hasDeliveryAddress()` false without a selected address, delivery
fields read from the selected `Address`.

**Implementation:**
1. `src/Resources/validation-rules.php`: rename `zip` → `postalCode`; add `cellPhone`, `personalPhone`, `fax`
   (Stripe's rules), `captureReason`, `refundDescription` (Story 6 uses them). Keep `email`.
2. `UserFieldReaderInterface`: `readBillingField()`, `hasDeliveryAddress()`, `readDeliveryField()`.
3. `OxidUserFieldReader`: MAP gains the three phones and `postalCode`; delivery via `User::getSelectedAddress()`
   (OXID `Address` model, `oxaddress__ox*`); static `oxidColumn()` unchanged.
4. `FieldValidationFailure`: `addressKind` (`billing` | `delivery` | `admin`).
5. `UserDataValidator`: `LOGICAL_FIELDS` = Stripe's 13 + `email`; `validateForUser()` runs billing, then delivery
   when present; `validateFieldMap(array $fields, string $addressKind = 'billing')`.
6. Update existing unit tests for the renamed field.

**Definition of Done:** validator reports billing and delivery failures with kind; standalone suite green.

## Story 3 — Describer, formatter, translations

**Estimate:** S

**Tests first:** `AllowedSymbolsDescriberTest` (class tokens → translated words, literals passed through, empty
when the field is unknown); `UserDataValidationMessageFormatterTest` (message = `FIELD_INVALID` with label and
allowed symbols; unknown field falls back to the raw name; `getPluginModuleId()` = `oe_payments_mollie`).

**Implementation:**
1. `Service\AllowedSymbolsDescriber` (extracted from the formatter), `ValidationRulesProvider::createDescriber()`
   factory; `services.yaml`: describer via factory, formatter takes `($translator, $describer)`.
2. Translations (`translations/{en,de}/mollie_lang.php`): `MOLLIE_VALIDATION_FIELD_INVALID`,
   `MOLLIE_VALIDATION_REVIEW_ADDRESS`, `MOLLIE_VALIDATION_UNDERSTAND`, `MOLLIE_VALIDATION_INVALID_USER_DATA`,
   `MOLLIE_VALIDATION_CLASS_{LETTERS,DIGITS,SPACES}`, `MOLLIE_VALIDATION_LABEL_<FIELD>` for all 14 fields.
   Admin (`views/admin_twig/{en,de}/mollie_lang.php`): `FIELD_INVALID`, `CLASS_*`, `LABEL_CAPTUREREASON`,
   `LABEL_REFUNDDESCRIPTION`. Also add the missing `MOLLIE_CHECKOUT_UNAVAILABLE` (renders raw today).

**Definition of Done:** formatter output matches Stripe's wording with Mollie labels; no raw keys in the flow.

## Story 4 — Order-step gate in `MollieOrderController::execute()`

**Estimate:** M

**Tests first:** Story 1's controller test; `testExecuteWhenUserDataValidProceedsToCheckoutSession`;
`testExecuteWhenValidatorUnavailableFailsOpen`; `MolliePaymentControllerTest`: failure shows per-field
messages instead of the generic key.

**Implementation:**
1. `Service\CheckoutUserDataGate` (one method `failuresFor(User $user): array`, wraps reader + validator; fail-open
   → `[]` with the logged warning). Registered public (OXID controllers fetch at runtime).
2. `MollieOrderController::execute()`: after the basket-hash guard and before the in-flight replay:
   `$block = $this->rejectInvalidUserData(); if ($block !== null) return $block;` — renders each failure via the
   tagged formatter + the review notice, returns `'user'`. Replay comes after: a shopper whose data went bad
   between clicks must not be replayed into Mollie.
3. PHPMD headroom: the controller is at 24 methods / complexity 49. Move the four submission guards (challenge,
   terms, basket hash, user data) into `Controller\Checkout\OrderSubmissionGuards` returning the controller to
   render (`?string`), so `execute()` shrinks instead of growing. Seams stay protected for the testable subclass.
4. `PaymentController::showInvalidUserDataError()` renders the formatted per-field messages (same formatter).

**Definition of Done:** invalid data never reaches `MollieCheckoutSessionRequestEvent`; e2e from Story 1 GREEN.

## Story 5 — OPC footer: live validation against the central endpoint

**Estimate:** M

**Tests first:** `MollieCheckoutFooterTest::testWidgetExposesValidationUrlPluginIdAndAllowedSymbols`;
e2e `tests/e2e/playwright/tests/MollieOpc/UserDataValidation.spec.ts` (OPC flag on for the run, off afterwards):
blocked character in the address → footer shows the field message, no `processCheckout`; fixed → pays.

**Implementation:**
1. `MollieCheckoutFooter::getCheckoutData()` adds `validationUrl`, `pluginModuleId`, `fieldAllowed`
   (`ValidationRulesProvider` + describer, translated).
2. `resources/js/controllers/mollie_user_data_validator_controller.js` (registered in `app.js`, not inline):
   wraps `mollie-checkout-footer#submitPayment`; collects the OPC address fields (`firstName`, `lastName`,
   `street`, `houseNumber`→`streetNumber`, `postalCode`→`zipCode`, `city`), POSTs FormData + `pluginModuleId` +
   `stoken` to the endpoint; on `{valid:false}` marks `.is-invalid`, sets `.invalid-feedback`, dispatches
   `oe:payment:error` with `MOLLIE_VALIDATION_REVIEW_ADDRESS`; fails open on non-OK.
3. `views/twig/widget/checkout/mollie-footer.html.twig`: `data-controller="mollie-checkout-footer
   mollie-user-data-validator"` + value attributes; `npm run build` / `build:dev`.

**Definition of Done:** OPC blocks invalid input before `processCheckout` with the same message the classic flow shows.

## Story 6 — Admin free text, changelog, docs, CI

**Estimate:** S

**Tests first:** `MolliePaymentPanelProviderTest`: `captureReason` / `refundDescription` with a blocked character
are refused and stored as feedback; `MolliePanelViewDataBuilderTest`: feedback rendered with the tagged
formatter's message.

**Implementation:**
1. `MolliePaymentPanelProvider`: `collectTextFailures()` → `validateFieldMap([...], 'admin')` →
   `validationFeedback->store()`; `MolliePanelViewDataBuilder` formats via `UserDataValidationMessageFormatter`.
2. `CHANGELOG.md` `[Unreleased] / Added`; `docs/for_developer` note on the three validation boundaries.
3. Full gates, `mollie-standard` + the two new e2e specs, push, CI green, sound; **wait for approval to merge**.

## Suggested order
1 → 2 → 3 → 4 (classic e2e green) → 5 (OPC e2e green) → 6.

## Open questions for the approver (defaults in bold)
- Field name for the postcode: **`postalCode` (Stripe parity)** vs keep `zip`.
- Classic-flow failure destination: **address step (`user`)** vs stay on the order step.
- Admin free-text rules: **Stripe's captureReason/refundDescription rules** vs looser.
