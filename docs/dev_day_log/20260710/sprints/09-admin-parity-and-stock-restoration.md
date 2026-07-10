# Sprint 9: Admin Panel Parity + Stock Restoration

**Reference:** `../20260629/_engeneering_requirements.md` (engineering requirements)  
**Parent:** `../20260629/sprints/00-roadmap.md` (Sprint 8 was hardening & parity; this sprint continues parity work)  
**Date:** 2026-07-10

## Overview

Address the 4 functional gaps identified in the [Stripe/Mollie admin parity review](../reports/01-stripe-mollie-admin-parity-review.md):
1. Stock restoration on admin refunds
2. View cache reset after admin actions
3. Refund description field
4. Transaction persistence (evaluation only)

## Engineering Requirements (from `_engeneering_requirements.md`)

| Principle | Application to Sprint 9 |
|-----------|--------------------------|
| **TDD-first** | Failing tests precede every implementation change |
| **DevOps-first** | Pre-commit passes (PHPCS, PHPStan max, PHPMD, PHPUnit Unit + Integration) |
| **SOLID / SRP** | Each gap fix is a single responsibility; no God methods |
| **SOLID / DIP** | Services depend on interfaces, not concrete implementations |
| **LSP** | OXID implementation honors interface contracts |
| **DRY** | Reuse existing `ContractRefundRecorder` instead of duplicating logic |
| **No overengineering** | Only add what's needed; defer transaction persistence if API reads suffice |

## Stories

### Story 1: Stock Restoration on Admin Refund
**As an** admin  
**I want** stock to be restored when I process a refund via the Mollie panel  
**So that** inventory stays accurate after refunds

**Acceptance Criteria:**
- [ ] Unit test: `RefundService` calls stock restoration after successful refund
- [ ] Unit test: `RefundService` does NOT restore stock if refund fails
- [ ] Integration test: admin refund action restores stock for all order articles
- [ ] Implementation: delegate to `StockRestorationServiceInterface` (payment-base contract)

**Files:**
- `src/Mollie/Service/RefundService.php` - add collaborator, call after successful refund
- `tests/Unit/Service/RefundServiceTest.php` - add stock restoration tests
- `tests/Integration/AdminRefundStockRestorationTest.php` - new integration test

**Dependencies:**
- `OxidEsales\PaymentBase\Service\StockRestorationServiceInterface` (payment-base)

---

### Story 2: View Cache Reset After Admin Actions
**As an** admin  
**I want** the panel to show fresh data immediately after I perform an action  
**So that** I see the correct remaining amounts without reloading

**Acceptance Criteria:**
- [ ] Unit test: `MolliePanelViewDataBuilder` has `resetViewCache()` method
- [ ] Unit test: `MolliePaymentPanelProvider` calls `resetViewCache()` after each action
- [ ] Implementation: add `resetViewCache()` method to builder
- [ ] Implementation: call `resetViewCache()` in `handleRefund()`, `handleCapture()`, `handleCancel()`

**Files:**
- `src/Mollie/Admin/MolliePanelViewDataBuilder.php` - add reset method
- `src/Mollie/Admin/MolliePaymentPanelProvider.php` - call reset after actions
- `tests/Unit/Admin/MolliePaymentPanelProviderTest.php` - add cache reset tests
- `tests/Unit/Admin/MolliePanelViewDataBuilderTest.php` - add reset tests

**Dependencies:**
- Story 1 (RefundService updated to support the pattern)

---

### Story 3: Refund Description Field
**As an** admin  
**I want** to add an optional description to a refund  
**So that** I can note the reason for audit purposes

**Acceptance Criteria:**
- [ ] Unit test: refund description is passed to `RefundService`
- [ ] Unit test: description is included in refund metadata/request
- [ ] Template test: description input appears in refund form
- [ ] Template test: description is optional (blank is valid)
- [ ] Implementation: add `refund_description` input to `mollie_panel.html.twig`
- [ ] Implementation: pass description through `OrderActionDispatcher` to `RefundService`
- [ ] Implementation: include description in `RefundRequest` DTO

**Files:**
- `views/twig/admin/panel/mollie_panel.html.twig` - add description input
- `src/Mollie/Controller/Admin/OrderActionDispatcher.php` - pass description
- `src/Mollie/Admin/MolliePaymentPanelProvider.php` - collect description from request
- `src/Mollie/Adapter/Dto/RefundRequest.php` - add description field
- `tests/Unit/Admin/MolliePaymentPanelProviderTest.php` - add description tests
- `tests/Unit/Adapter/Dto/RefundRequestTest.php` - add description tests

**Translations:**
- `views/twig/en/mollie_lang.php` - add `MOLLIE_REFUND_DESCRIPTION` key
- `views/twig/de/mollie_lang.php` - add German translation

---

### Story 4: Transaction Persistence Evaluation (Deferred)
**As an** admin  
**I want** to see transaction history persisted to DB  
**So that** I have an audit trail independent of Mollie API

**Status:** Deferred

**Decision:** After discussion, decide if `TransactionHistoryService` should persist to
`oe_payments_transaction` or if API reads are sufficient. API reads are simpler and Mollie
guarantees payment state.

**Acceptance Criteria:**
- [ ] Document decision in `../done/transaction-persistence-decision.md`
- [ ] If persistence needed: add story to next sprint

---

### Story 5: E2E Walkthrough Report with Playwright Tests
**As a** developer
**I want** a documented walkthrough proving the complete refund flow works end-to-end
**So that** we have evidence of the implemented features working in a real browser scenario

**Acceptance Criteria:**
- [ ] **Checkout Playwright Test** (`tests/E2E/checkout-mollie.spec.ts`):
  - [ ] Navigate to shop frontend
  - [ ] Add product to cart
  - [ ] Proceed to checkout with Mollie payment method
  - [ ] Complete checkout (trigger Mollie redirect)
  - [ ] Simulate Mollie webhook callback (payment.paid)
  - [ ] Verify order is created with correct status
- [ ] **Admin Panel Playwright Test** (`tests/E2E/admin-mollie-refund.spec.ts`):
  - [ ] Login to admin
  - [ ] Open the created order
  - [ ] Verify payment details section displays correctly
  - [ ] Verify captured amount is shown
  - [ ] Verify refundable amount is shown
  - [ ] Verify transaction history table is populated
- [ ] **Partial Refund Sequence** (within admin test):
  - [ ] **Refund #1:** Enter 10% of order total → Submit → Verify remaining
  - [ ] **Refund #2:** Enter 20% of order total → Submit → Verify cumulative (30%)
  - [ ] **Refund #3:** Enter remaining → Submit → Verify fully refunded
  - [ ] **Stock Check:** Verify stock quantities were restored after each refund
  - [ ] **API Verification:** Confirm amounts in admin panel match Mollie API/Dashboard
- [ ] **Walkthrough Report** (`../walkthrough/01-e2e-refund-flow.md`):
  - [ ] Document test environment (sandbox credentials, URLs)
  - [ ] Include screenshots at key steps
  - [ ] Log all refund amounts and cumulative totals
  - [ ] Verify all amounts match Mollie dashboard
  - [ ] Confirm stock restoration timestamps

**Files:**
- `tests/E2E/checkout-mollie.spec.ts` - Playwright checkout test
- `tests/E2E/admin-mollie-refund.spec.ts` - Playwright admin refund test
- `../walkthrough/01-e2e-refund-flow.md` - Generated walkthrough report

**Prerequisites:**
- Valid Mollie test API key (`test_` prefix) in test environment
- Playwright test infrastructure configured (see `../20260629/sprints/07-admin-panel-and-frontend.md`)

**Note:** This story can run in parallel with Stories 1-3 once test credentials are available.

## DevOps Gates

### Quality Gate: `./bin/pre-commit-check.sh`

**All stories must pass the quality gate before commit.** This script runs all checks sequentially and exits non-zero if any fail.

```bash
# Navigate to module root
cd /home/dtkachev/osc/strpwt7-nov26/source/extensions/mollie-payment

# Run pre-commit checks (unit tests only)
./bin/pre-commit-check.sh

# Run full suite (unit + integration tests)
./bin/pre-commit-check.sh --full

# Run without PHPUnit (CI mode - checks only)
./bin/pre-commit-check.sh --no-phpunit
```

**Quality Gate Components:**

| Check | Command | Standard | Threshold |
|-------|---------|---------|-----------|
| **PHP CodeSniffer** | `composer phpcs` | PSR-12 | 0 violations |
| **PHPStan** | `composer phpstan` | Level max (8) | 0 errors |
| **PHP Mess Detector** | `composer phpmd` | Strict rules | 0 violations |
| **PHPUnit Unit** | `vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit` | All tests | 100% pass |
| **PHPUnit Integration** | `vendor/bin/phpunit -c tests/phpunit.xml --testsuite Integration` | All tests | 100% pass |

**Expected results after sprint:**
- PHPCS: 0 violations
- PHPStan: level max, 0 errors
- PHPMD: 0 violations
- PHPUnit Unit: all pass
- PHPUnit Integration: all pass
- Total tests: ~385 (was 377, +8 new tests)

**Pre-commit Hook (optional):**
```bash
# Install as git pre-commit hook
ln -s ../../bin/pre-commit-check.sh .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

**CI Integration:**
```bash
# For CI pipelines - run quality gate without PHPUnit first
./bin/pre-commit-check.sh --no-phpunit

# Then run tests separately
cd /home/dtkachev/osc/strpwt7-nov26/source/extensions/mollie-payment
php vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit
php vendor/bin/phpunit -c tests/phpunit.xml --testsuite Integration
```

## Implementation Order

```
1. Story 1 (Stock Restoration)
   ├── TDD: Write failing test for stock restoration
   ├── Implement: Add StockRestorationServiceInterface to RefundService
   ├── Verify: Test passes
   └── Repeat for each test

2. Story 2 (View Cache Reset)
   ├── TDD: Write failing test for cache reset
   ├── Implement: Add resetViewCache() to MolliePanelViewDataBuilder
   ├── Implement: Call reset in MolliePaymentPanelProvider
   └── Verify: Tests pass

3. Story 3 (Refund Description)
   ├── TDD: Write tests for description field
   ├── Implement: Add description to RefundRequest DTO
   ├── Implement: Add input to template
   ├── Implement: Wire through dispatcher
   └── Verify: Tests pass

4. Story 4 (Deferred)
   └── Document decision

5. E2E Walkthrough (Parallel)
   ├── Run Playwright checkout test to create Mollie order
   ├── Run Playwright admin test to verify panel functionality
   ├── Perform multiple partial refunds via admin panel
   ├── Document all steps and verify amounts match Mollie API
   └── Generate walkthrough report in `../walkthrough/01-e2e-refund-flow.md`
```

## Definitions of Done

### Story 1 Done When:
- [ ] All unit tests pass
- [ ] Integration test passes (stock restored after admin refund)
- [ ] No PHPCS/PHPStan/PHPMD violations
- [ ] Code review approved

### Story 2 Done When:
- [ ] All unit tests pass
- [ ] Cache is reset after each admin action (refund/capture/cancel)
- [ ] No regressions in existing tests
- [ ] Code review approved

### Story 3 Done When:
- [ ] All unit tests pass
- [ ] Template renders description input
- [ ] Description is optional (blank submits successfully)
- [ ] Description appears in Mollie refund metadata (verified via test or API)
- [ ] Translations added (EN + DE)
- [ ] Code review approved

### Sprint Done When:
- [x] Stories 1-3 complete ✅
- [ ] Story 4 decision documented (deferred)
- [ ] Story 5 (E2E Walkthrough) - **BLOCKED: Mollie API key not configured**
- [x] All DevOps gates green ✅
- [x] `docs/dev_day_log/20260710/status.md` updated ✅
- [ ] Module still activates successfully (verify after merge)
- [ ] **Walkthrough Report** (`../walkthrough/01-e2e-refund-flow.md`) - **BLOCKED**:
  - [x] Checkout Test: Created, ready to run
  - [ ] **Admin Panel Test:** BLOCKED - requires Mollie API key configured
  - [ ] **Partial Refund Test #1:** BLOCKED - no order created (API key missing)
  - [ ] **Verification #1:** BLOCKED - no order created
  - [ ] **Partial Refund Test #2:** BLOCKED
  - [ ] **Verification #2:** BLOCKED
  - [ ] **Partial Refund Test #3:** BLOCKED
  - [ ] **Final Verification:** BLOCKED
  - [ ] **Stock Verification:** BLOCKED

### Story 5 Status: BLOCKED - Mollie API Key Not Configured

**Created:**
- `tests/e2e/playwright/tests/MollieAdmin/AdminRefundFlow.spec.ts` - Full E2E test suite:
  - Step 1: Checkout → Creates Mollie order
  - Step 2: Admin panel verification
  - Step 3: Multi-partial-refund sequence (10% → 20% → remaining)
  - Step 4: Stock restoration verification (placeholder)
- `tests/e2e/playwright/playwright.config.ts` - Added `mollie-admin` project
- `docs/dev_day_log/20260710/walkthrough/01-e2e-refund-flow.md` - Report template with blocking status

**Debug Test Results:**
- ✅ Login to shop works
- ✅ Mollie payment method exists in checkout (`oe_payments_mollie`)
- ❌ **MOLLIE_CHECKOUT_UNAVAILABLE** - Mollie API key not configured
- ✅ Admin login works (but panel is in staging mode)

**Required Action:**
1. Configure Mollie test API key in shop admin: https://daniil.oxiddev.de/admin/
2. Go to: Modules → Mollie Payment → Settings
3. Enter a Mollie Test API Key (`test_...`)
4. Run E2E tests
5. Populate walkthrough report with results