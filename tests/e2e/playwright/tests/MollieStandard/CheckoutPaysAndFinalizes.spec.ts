import { test, expect } from '@playwright/test';
import {
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    completeMollieTestPayment,
} from '../../fixtures/shop-helpers';

/**
 * CI/manual-run-only — requires a real Mollie sandbox (test-mode) API key configured on the
 * shop under test. See README.md. Not run as part of this sprint's PHP quality gates.
 *
 * Proves the full happy-path spine end to end:
 *   standard checkout -> Mollie sandbox pay -> return -> webhook finalizes -> thank-you + OXPAID.
 *
 * Because the webhook delivery is asynchronous (Mollie calls the shop's server, not the
 * browser), this spec polls the thank-you/order-history page rather than asserting the instant
 * the browser returns — the browser-return leg alone is not guaranteed to finalize the order
 * (see docs/architecture/00-overview.md: both the return AND the webhook can independently
 * advance the contract; the webhook is authoritative).
 */
test.describe('Mollie standard checkout — happy path', () => {
    test('customer pays with Mollie and the order finalizes', async ({ page }) => {
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page, 'ideal');
        await continueToOrderReview(page);

        await page.getByRole('button', { name: /place order|order now|zahlungspflichtig/i }).click();

        // MolliePaymentController::execute() issues a 302 straight to Mollie's hosted checkout —
        // no intermediate OXID page in between (see docs/architecture/00-overview.md).
        await completeMollieTestPayment(page, 'paid');

        // Mollie 302s back to MollieOrderController::checkoutReturn(), which either finalizes
        // immediately or (if the webhook beats the browser back) leaves it to the webhook —
        // either way the customer must land on the thank-you page, never a raw error.
        await expect(page).toHaveURL(/cl=thankyou|fnc=thankyou/i, { timeout: 30_000 });

        // The webhook is authoritative for OXPAID; give it a moment to arrive if the browser won.
        await expect(async () => {
            await page.reload();
            await expect(page.locator('body')).not.toContainText(/error|failed/i);
        }).toPass({ timeout: 30_000 });
    });
});
