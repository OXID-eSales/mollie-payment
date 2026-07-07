import { test, expect } from '@playwright/test';
import {
    loginAdmin,
    navigateToOrders,
    readOrderRows,
    openFirstMollieOrder,
    readMolliePanel,
    isValidPaymentDate,
} from '../../fixtures/admin-helpers';

/**
 * Admin-side verification of a completed Mollie order — the Mollie counterpart of Stripe's
 * `tests/admin/payment-date-validation.spec.ts` and `tests/admin/stripe-admin-order.spec.ts`.
 *
 * What it proves once a customer has paid through Mollie:
 *   - the order carries a valid OXPAID payment date in the admin order list (fields + values), and
 *   - the shared "Payment" tab renders the Mollie panel with the payment details (contract id,
 *     Mollie payment id `tr_...`, captured/refunded amounts, dashboard deep-link).
 *
 * Requires a shop that already has a completed Mollie order: the `CheckoutPaysAndFinalizes` spec
 * (or a prior manual/CI run) produces one. Admin credentials come from ADMIN_USER_EMAIL /
 * ADMIN_USER_PASSWORD (see `.env.dist`). Like the Stripe originals, each test logs in and
 * navigates independently so it can be run in isolation.
 */
test.describe('Mollie order completion — admin verification', () => {

    test('order list shows a valid OXPAID payment date for paid orders', async ({ page }) => {
        await loginAdmin(page);
        const list = await navigateToOrders(page);

        const rows = await readOrderRows(list);
        const validCount = rows.filter((r) => r.hasValidPaymentDate).length;

        console.log(`Orders in list: ${rows.length}, with valid payment date: ${validCount}`);
        for (const row of rows.slice(0, 10)) {
            console.log(`  #${row.orderNumber}: order=${row.orderDate} | paid=${row.paymentDate} | valid=${row.hasValidPaymentDate}`);
        }

        // Positive assertion (as in the Stripe original): at least one order must carry a valid
        // OXPAID, confirming the checkout → payment → finalize flow wrote the payment date.
        expect(validCount, 'Expected at least one order with a valid OXPAID payment date').toBeGreaterThan(0);
    });

    test('a Mollie order exposes its payment details on the Payment tab', async ({ page }) => {
        await loginAdmin(page);
        await navigateToOrders(page);

        const found = await openFirstMollieOrder(page);
        expect(found, 'Expected to find a completed Mollie order in the list').not.toBeNull();
        if (!found) {
            return;
        }

        const details = await readMolliePanel(found.edit);
        console.log(`Mollie order #${found.row.orderNumber}:`, JSON.stringify(details));

        // The shared payment tab identifies the provider that owns the panel.
        expect(details.provider).toBe('mollie');

        // Contract id links the order to its payment-base contract.
        expect(details.contractId, 'Contract ID should be displayed').toBeTruthy();

        // Mollie payment id is `tr_...` (the id Mollie POSTs to the webhook).
        expect(details.paymentId, 'Mollie payment id should be displayed').toBeTruthy();
        expect(details.paymentId as string).toMatch(/^tr_[a-zA-Z0-9]+$/);

        // Captured amount is always rendered (defaults to 0.00 with the currency suffix).
        expect(details.capturedAmount, 'Captured amount should be displayed').toBeTruthy();

        // The paid order's OXPAID in the list must be a real date, not the zero date.
        expect(
            isValidPaymentDate(found.row.paymentDate),
            `Order #${found.row.orderNumber} should have a valid OXPAID (got "${found.row.paymentDate}")`,
        ).toBe(true);
    });

    test('the Mollie payment id deep-links to the Mollie dashboard', async ({ page }) => {
        await loginAdmin(page);
        await navigateToOrders(page);

        const found = await openFirstMollieOrder(page);
        expect(found, 'Expected to find a completed Mollie order in the list').not.toBeNull();
        if (!found) {
            return;
        }

        const details = await readMolliePanel(found.edit);

        // The dashboard link is only rendered when a dashboard URL is configured for the panel.
        if (!details.dashboardLink) {
            console.log('No dashboard link rendered for this order — dashboard URL not configured; skipping.');
            return;
        }

        console.log(`Dashboard link: ${details.dashboardLink}`);
        expect(details.dashboardLink).toContain('my.mollie.com');
        expect(details.dashboardLink).toContain('/payments/');
        // The link must target this order's payment id.
        expect(details.dashboardLink).toContain(details.paymentId as string);
    });

});
