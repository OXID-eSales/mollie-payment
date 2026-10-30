import { test, expect, Page } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    completeMollieTestPayment,
    openOpcCheckoutModal,
    chooseMollieInOpcModal,
    submitOpcMollieFooter,
    OPC_FOLD_SKIP,
} from '../../fixtures/shop-helpers';
import { shopDbQuery } from '../../fixtures/shop-db';

/**
 * PSP-SWITCH — after a cancel at PayPal, "Pay" with Mollie must open Mollie, not PayPal.
 *
 * A cancel at PayPal keeps the attempt open (OPC-175: one order, one contract), with the PayPal
 * approval URL on its contract. The MOL-18 replay used to rejoin that attempt and send the Mollie
 * start to PayPal; the order kept OXPAYMENTTYPE = oe_payments_paypal.
 *
 * The standard checkout (`cl=order`) runs the same CheckoutSessionStarter ladder; it is not driven
 * here because it needs OPC switched off in the shop (MollieOrderControllerTest covers it).
 *
 * The test: open a PayPal attempt (OPC processCheckout), come back through PayPal's cancel_url,
 * then pay with Mollie in test mode up to the thank-you page. The order of the checkout must be the
 * one order of the PayPal attempt, now with Mollie, and its one contract must name PayPal as the
 * replaced attempt.
 */

async function readStoken(page: Page): Promise<string> {
    return page.evaluate(() =>
        (document.querySelector('input[name="stoken"]') as HTMLInputElement | null)?.value
        ?? document.querySelector('[data-basket-operations-csrf-token-value]')
            ?.getAttribute('data-basket-operations-csrf-token-value')
        ?? '');
}

async function opcPost(page: Page, controller: string, fnc: string, fields: Record<string, string>): Promise<Record<string, unknown>> {
    const token = await readStoken(page);
    return page.evaluate(async ({ controller, fnc, fields, token }) => {
        const response = await fetch(`${location.origin}/index.php?cl=${controller}&fnc=${fnc}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ ...fields, stoken: token }).toString(),
        });
        return response.json();
    }, { controller, fnc, fields, token });
}

interface PayPalAttempt {
    contractId: string;
    orderId: string;
}

/** What the OPC footer does on "Pay" with PayPal, then what PayPal does on "Cancel". */
async function startPayPalAndCancel(page: Page): Promise<PayPalAttempt> {
    await page.goto('/index.php?cl=basket', { waitUntil: 'domcontentloaded' });
    const selected = await opcPost(page, 'OePaymentMethod', 'setPaymentMethod', { paymentMethodId: 'oe_payments_paypal' });
    expect(selected.success, 'PayPal selected').toBe(true);
    const started = await opcPost(page, 'OeCheckoutApi', 'processCheckout', {
        paymentMethodId: 'oe_payments_paypal',
        confirmTermsAndConditions: '1',
        confirmPrivacyPolicy: '1',
    });
    expect(started.success, `PayPal start: ${JSON.stringify(started)}`).toBe(true);

    const contractId = String(started.contractId ?? '');
    const [provider, providerData, orderId] = shopDbQuery(
        `SELECT OXPROVIDER, OXPROVIDERDATA, OXORDERID FROM oe_payments_contract WHERE OXID = '${contractId}'`,
    )[0];
    expect(provider).toBe('paypal');
    expect(providerData, 'the PayPal attempt has its approval URL').toContain('paypal.com');
    expect(orderId, 'the PayPal attempt has its early order').not.toBe('NULL');

    await page.goto('/index.php?cl=PayPalOrderController&fnc=paypalCheckoutCancel', { waitUntil: 'domcontentloaded' });

    return { contractId, orderId };
}

/**
 * The order of the PayPal attempt continues and ends paid with Mollie; it has exactly one contract
 * - the PayPal one, re-bound to Mollie. Read by id: other sessions may finish orders meanwhile.
 */
function expectTheOrderContinuesWithMollie(attempt: PayPalAttempt): void {
    const [orderNr, paymentType, transStatus, storno] = shopDbQuery(
        `SELECT OXORDERNR, OXPAYMENTTYPE, OXTRANSSTATUS, OXSTORNO FROM oxorder WHERE OXID = '${attempt.orderId}'`,
    )[0];
    expect(paymentType, 'oxorder.OXPAYMENTTYPE').toBe('oe_payments_mollie');
    expect(transStatus).toBe('OK');
    expect(storno).toBe('0');

    const contracts = shopDbQuery(
        'SELECT OXID, OXPROVIDER, JSON_EXTRACT(OXMETADATA, \'$.replaced_attempts[0].provider\') ' +
            `FROM oe_payments_contract WHERE OXORDERID = '${attempt.orderId}'`,
    );
    expect(contracts, 'the order has exactly one contract').toHaveLength(1);
    expect(contracts[0][0], 'the contract of the PayPal attempt continues').toBe(attempt.contractId);
    expect(contracts[0][1]).toBe('mollie');
    expect(contracts[0][2]).toBe('"paypal"');
    console.log(`[PSP-SWITCH] order=${attempt.orderId} nr=${orderNr} contract=${attempt.contractId} ` +
        `OXPAYMENTTYPE=${paymentType} OXTRANSSTATUS=${transStatus}`);
}

async function expectThankYou(page: Page): Promise<void> {
    await page.waitForURL(/cl=thankyou|fnc=checkoutReturn/i, { timeout: 45_000 });
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).toContainText(/Vielen Dank|thank you/i);
}

test.describe('PSP-SWITCH — PayPal cancelled, then Mollie pays', () => {
    test('OPC: the Mollie footer opens Mollie, the order finalizes with Mollie', async ({ page }, testInfo) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        const attempt = await startPayPalAndCancel(page);

        const modal = await openOpcCheckoutModal(page);
        test.skip(await chooseMollieInOpcModal(modal) === 'folded', OPC_FOLD_SKIP);
        await page.waitForTimeout(1500);
        await submitOpcMollieFooter(modal);

        await expect(page, 'the Mollie start goes to Mollie, not to PayPal').toHaveURL(/mollie\.com\/checkout/i, { timeout: 45_000 });
        await testInfo.attach('mollie-checkout', { body: await page.screenshot(), contentType: 'image/png' });
        await completeMollieTestPayment(page, 'paid');
        await expectThankYou(page);
        await testInfo.attach('thank-you', { body: await page.screenshot(), contentType: 'image/png' });

        expectTheOrderContinuesWithMollie(attempt);
    });
});
