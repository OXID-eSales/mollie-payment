# Stripe vs Mollie Admin Payment Tab Parity Review

**Date:** 2026-07-10  
**Module:** Mollie payment module  
**Reference:** Stripe module admin panel implementation

## Executive Summary

Mollie's admin payment tab (`MolliePaymentPanelProvider`) is structurally complete and mirrors
Stripe's implementation pattern. Both modules follow the payment-base architecture with:
- `PaymentPanelProvider` → `PanelViewDataBuilder` → template chain
- `OrderActionDispatcher` dispatching via event system
- Admin validation feedback via session channel
- Amount validation with semantic error codes

However, the review identified **4 functional gaps** where Mollie differs from Stripe's behavior.

---

## Parity Analysis

### 1. Stock Restoration on Refund

| Aspect | Stripe | Mollie |
|--------|--------|--------|
| **Behavior** | Calls `StockRestorationServiceInterface::restoreStockForOrder()` after successful refund | Does NOT restore stock |
| **Location** | `RefundService::handleRefundResponse()` | N/A |
| **Rationale** | Stripe refunds may come from webhook (paid) or admin panel | Mollie webhook handles paid refunds; admin refunds need same treatment |

**Stripe Implementation:**
```php
// Stripe/Service/RefundService.php
if ($orderId !== null) {
    $articlesProcessed = $this->stockRestorationService->restoreStockForOrder($orderId);
    $this->logger->info('Stock restored after refund', ['orderId' => $orderId, ...]);
}
```

**Mollie Gap:** `RefundService::refund()` calls `$this->refundRecorder->record()` but does NOT
trigger stock restoration. This means admin-initiated refunds won't restore stock.

---

### 2. View Cache Reset After Admin Actions

| Aspect | Stripe | Mollie |
|--------|--------|--------|
| **Behavior** | Calls `viewDataBuilder->resetViewCache()` after refund/capture/cancel | No cache reset mechanism |
| **Location** | `StripePaymentPanelProvider::handleRefund()`, `handleCapture()`, `handleCancel()` | N/A |
| **Rationale** | Same HTTP request re-renders panel after action; stale API data must be busted | N/A |

**Stripe Implementation:**
```php
// Stripe/Service/StripePaymentPanelProvider.php
private function handleRefund(Order $order, array $request): void
{
    $this->actionDispatcher->refund(...);
    $this->viewDataBuilder->resetViewCache(); // ← Key line
}
```

**Mollie Gap:** `MolliePaymentPanelProvider` does NOT call any cache reset. After an admin action,
the panel renders with stale data until the next page load.

---

### 3. Refund Description Field

| Aspect | Stripe | Mollie |
|--------|--------|--------|
| **Field** | `refund_description` input in template | Only `refund_reason` |
| **Storage** | Stored in Stripe refund metadata as `description` | No description support |
| **Template** | `stripe_panel.html.twig` has input field | `mollie_panel.html.twig` has no description |

**Stripe Template:**
```twig
<div class="s-form-group">
    <label class="s-form-label" for="refund_reason">{{ translate(...) }}</label>
    <input type="text" class="s-form-input" id="refund_reason" name="refund_reason" ...>
</div>
{# Sprint 121: description field was added #}
```

**Mollie Gap:** Admin has no way to add a description to the refund. Description is useful for
audit trail and can be retrieved from Mollie API.

---

### 4. Transaction DB Persistence

| Aspect | Stripe | Mollie |
|--------|--------|--------|
| **Source of truth** | Stripe API via `OrderRefundViewDataProvider` | Mollie API via `TransactionHistoryService` |
| **DB recording** | `TransactionRecordingHandler` records to `oe_payments_transaction` | Not explicit; only in-memory API calls |
| **Stock restoration** | Triggers via `StockRestorationService` | Only via webhook path |

**Mollie Issue:** `TransactionHistoryService::fetch()` reads from Mollie API but doesn't persist
to the DB. `StripeTransactionRecordingHandler` explicitly creates `Transaction` records.

---

## Shared Features (Parity Achieved)

| Feature | Stripe | Mollie | Status |
|---------|--------|--------|--------|
| Payment details card | ✅ | ✅ | ✓ |
| Transaction history table | ✅ | ✅ | ✓ |
| Capture section (amount/reason) | ✅ | ✅ | ✓ |
| Cancel authorization section | ✅ | ✅ | ✓ |
| Refund section (amount/reason) | ✅ | ✅ | ✓ |
| Admin validation feedback | ✅ | ✅ | ✓ |
| Amount validation (malformed, precision, exceeds bound) | ✅ | ✅ | ✓ |
| Event system handlers | ✅ | ✅ | ✓ |
| Language translations | ✅ | ✅ | ✓ |
| Mollie Dashboard link | ✅ | ✅ | ✓ |
| User data validation | ✅ | ✅ | ✓ |
| Validation rules provider | ✅ | ✅ | ✓ |
| `services.yaml` admin panel tag | ✅ | ✅ | ✓ |
| Idempotent cancel | ✅ | ✅ | ✓ |

---

## Detailed File Comparison

### PaymentPanelProvider

| Method | Stripe | Mollie |
|--------|--------|--------|
| `getProviderName()` | ✅ | ✅ |
| `supports()` | ✅ | ✅ |
| `build()` | ✅ | ✅ |
| `handleAction()` | ✅ | ✅ |
| `handleRefund()` | ✅ + cache reset | ⚠️ Missing cache reset |
| `handleCapture()` | ✅ + cache reset | ⚠️ Missing cache reset |
| `handleCancel()` | ✅ + cache reset | ⚠️ Missing cache reset |

### RefundService

| Feature | Stripe | Mollie |
|--------|--------|--------|
| Full refund (null amount) | ✅ | ✅ |
| Partial refund | ✅ | ✅ |
| Amount validation (> 0) | ✅ | ✅ |
| Bound validation | ✅ | ✅ |
| Stock restoration | ✅ | ❌ Missing |
| Description metadata | ✅ | ❌ Missing |
| Reason validation | ✅ | ⚠️ Reason enum not validated |

### PanelViewDataBuilder

| Method | Stripe | Mollie |
|--------|--------|--------|
| Contract identity | ✅ | ✅ |
| Amounts (captured/refunded) | ✅ | ✅ |
| Bounds (capture/refund) | ✅ | ✅ |
| Eligibility flags | ✅ | ✅ |
| Dashboard link | ✅ | ✅ |
| Transaction history | ✅ | ✅ |
| Validation errors | ✅ | ✅ |
| `resetViewCache()` | ✅ | ❌ Missing |

---

## Recommendations

### Priority 1: Stock Restoration (Critical)
Admin-initiated refunds must restore stock. Delegate to `ContractRefundRecorder` or add a
`StockRestorationServiceInterface` collaborator.

### Priority 2: View Cache Reset (Medium)
Add `resetViewCache()` method to `MolliePanelViewDataBuilder` and call from
`MolliePaymentPanelProvider` after each admin action.

### Priority 3: Refund Description (Low)
Add optional `refund_description` field to template and pass through to service metadata.
Mollie API supports description on refunds.

### Priority 4: Transaction Persistence (Low)
Consider whether `TransactionHistoryService` should persist to `oe_payments_transaction` or
if API reads are sufficient for the admin panel use case.

---

## Test Coverage

Mollie has good unit test coverage but lacks:
- Integration test for stock restoration on admin refund
- Regression test for view cache reset (same HTTP request re-render)
- E2E test for refund description field flow