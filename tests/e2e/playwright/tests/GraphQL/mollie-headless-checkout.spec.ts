import { test, expect, APIRequestContext, Page } from '@playwright/test';
import { shopDbQuery } from '../../fixtures/shop-db';

/**
 * GRAPH-QL / MS6 — the headless Mollie checkout end-to-end, with no Twig page
 * involved: the GraphQL Storefront mutations create the basket, Mollie's
 * `mollieCheckoutStart` opens the contract (early order, Mollie payment), the
 * shopper pays on Mollie's hosted TEST page, Mollie's webhook ends the order,
 * and `mollieCheckoutReturn` only reports. Cancel, a wrong token, a failed
 * payment and the core placeOrder guard are covered too.
 *
 * Environment:
 *   SHOP_URL / MOLLIE_E2E_SHOP_URL   the shop (its origin is always an allowed return origin)
 *   HEADLESS_GRAPHQL_URL             GraphQL endpoint (default `${SHOP_URL}/graphql/`)
 *   HEADLESS_USER_EMAIL / HEADLESS_USER_PASSWORD   a customer in a country with a delivery set
 *                                    (default headless.user@oxid-esales.dev / useruser)
 *   HEADLESS_PRODUCT_ID              an orderable article (default: "Panorama", 20.90 EUR)
 *
 * Mollie's webhook must reach the shop (the tunnel does); the spec waits for it
 * by polling the contract state in the shop DB (fixtures/shop-db.ts).
 */

const SHOP_URL = (process.env.MOLLIE_E2E_SHOP_URL || process.env.SHOP_URL || 'https://localhost.local').replace(/\/?$/, '/');
const GRAPHQL_URL = process.env.HEADLESS_GRAPHQL_URL || `${SHOP_URL}graphql/`;
const USER_EMAIL = process.env.HEADLESS_USER_EMAIL || 'headless.user@oxid-esales.dev';
const USER_PASSWORD = process.env.HEADLESS_USER_PASSWORD || 'useruser';
const PRODUCT_ID = process.env.HEADLESS_PRODUCT_ID || '5e6a374e212258abbfd76b6adf911772';
const RETURN_URL = `${SHOP_URL}index.php?cl=start&headless=mollie-return`;
// OXID disables the basket for user agents it takes for search engines; the
// API client must look like a browser, as a real headless client does.
const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36 headless-e2e';

type GqlResponse = { data?: Record<string, any>; errors?: { message: string; extensions?: Record<string, any> }[] };

async function gql(request: APIRequestContext, query: string, token?: string): Promise<GqlResponse> {
    const response = await request.post(GRAPHQL_URL, {
        headers: {
            'Content-Type': 'application/json',
            'User-Agent': USER_AGENT,
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        data: { query },
        ignoreHTTPSErrors: true,
    });
    expect(response.ok(), `GraphQL HTTP ${response.status()}`).toBeTruthy();
    return (await response.json()) as GqlResponse;
}

function dataOf(result: GqlResponse, field: string): any {
    expect(result.errors, `unexpected GraphQL errors: ${JSON.stringify(result.errors)}`).toBeUndefined();
    return result.data?.[field];
}

async function login(request: APIRequestContext): Promise<string> {
    const token = dataOf(await gql(request, `{ token(username: "${USER_EMAIL}", password: "${USER_PASSWORD}") }`), 'token');
    expect(typeof token).toBe('string');
    return token;
}

async function basketWithOneProduct(request: APIRequestContext, token: string): Promise<string> {
    const basketId = dataOf(
        await gql(request, `mutation { basketCreate(basket: { title: "headless-mollie-${Date.now()}", public: false }) { id } }`, token),
        'basketCreate',
    ).id as string;
    const added = dataOf(
        await gql(request, `mutation { basketAddItem(basketId: "${basketId}", productId: "${PRODUCT_ID}", amount: 1) { id cost { total } } }`, token),
        'basketAddItem',
    );
    expect(added.cost.total, 'the basket must have a price (a bot-like user agent zeroes it)').toBeGreaterThan(0);
    return basketId;
}

async function startMollieCheckout(request: APIRequestContext, token: string, basketId: string, method?: string) {
    const methodArg = method ? `, method: "${method}"` : '';
    const start = dataOf(
        await gql(
            request,
            `mutation { mollieCheckoutStart(basketId: "${basketId}", confirmTermsAndConditions: true, returnUrl: "${RETURN_URL}"${methodArg}) { contractId contractToken providerName orderNumber redirectUrl clientSecret renderMode } }`,
            token,
        ),
        'mollieCheckoutStart',
    );
    expect(start.providerName).toBe('mollie');
    expect(start.renderMode).toBe('redirect');
    expect(start.redirectUrl).toMatch(/mollie\.com/);
    expect(start.clientSecret).toBeNull();
    return start as { contractId: string; contractToken: string; orderNumber: string; redirectUrl: string };
}

function contractState(contractId: string): string {
    const rows = shopDbQuery(`SELECT OXSTATE FROM oe_payments_contract WHERE OXID = '${contractId}'`);
    return rows[0]?.[0] ?? '';
}

function orderOf(contractId: string): { transStatus: string; paid: string; storno: string } {
    const rows = shopDbQuery(
        `SELECT o.OXTRANSSTATUS, o.OXPAID, o.OXSTORNO FROM oxorder o JOIN oe_payments_contract c ON c.OXORDERID = o.OXID WHERE c.OXID = '${contractId}'`,
    );
    return { transStatus: rows[0]?.[0] ?? '', paid: rows[0]?.[1] ?? '', storno: rows[0]?.[2] ?? '' };
}

/** Mollie's webhook is asynchronous; wait until the contract left the given states. */
async function waitForContractToLeave(contractId: string, states: string[], timeoutMs = 60_000): Promise<string> {
    const deadline = Date.now() + timeoutMs;
    let state = contractState(contractId);
    while (states.includes(state) && Date.now() < deadline) {
        await new Promise((r) => setTimeout(r, 2000));
        state = contractState(contractId);
    }
    return state;
}

/** Pick an outcome on Mollie's TEST-mode page (method page first when no method was pinned). */
async function payOnMollieTestPage(page: Page, redirectUrl: string, outcome: 'Paid' | 'Failed' | 'Canceled'): Promise<void> {
    await page.goto(redirectUrl);
    await expect(page).toHaveURL(/mollie\.com\/checkout/i, { timeout: 30_000 });
    if (!/test-mode/i.test(page.url())) {
        await page.getByRole('button', { name: /^paypal$/i }).first().click();
        await page.waitForURL(/mollie\.com\/checkout\/test-mode/i, { timeout: 30_000 });
    }
    await page.getByText(outcome, { exact: true }).click();
    await page.getByRole('button', { name: /continue|weiter/i }).first().click();
}

test.describe('GraphQL headless Mollie checkout (GRAPH-QL / MS6)', () => {
    test('start → pay on Mollie → the webhook ends the order; return only reports', async ({ page, request }) => {
        const token = await login(request);
        const basketId = await basketWithOneProduct(request, token);
        const start = await startMollieCheckout(request, token, basketId, 'paypal');
        expect(contractState(start.contractId)).toBe('pending');
        expect(orderOf(start.contractId).transStatus).toBe('NOT_FINISHED');

        await payOnMollieTestPage(page, start.redirectUrl, 'Paid');
        // Mollie sends the browser to the client's URL with our contract id
        await page.waitForURL(new RegExp(`headless=mollie-return.*contract_id=${start.contractId}`), { timeout: 60_000 });

        const state = await waitForContractToLeave(start.contractId, ['pending', 'not_finished']);
        expect(['committed', 'fulfilled'], 'the webhook committed the contract, no return leg involved').toContain(state);
        const order = orderOf(start.contractId);
        expect(order.transStatus).toBe('OK');
        expect(order.paid, 'PayPal settles at once: paid').not.toMatch(/^0000/);

        const returned = dataOf(
            await gql(request, `mutation { mollieCheckoutReturn(contractId: "${start.contractId}", contractToken: "${start.contractToken}") { status orderId orderNumber contractState } }`, token),
            'mollieCheckoutReturn',
        );
        expect(returned.status).toBe('committed');
        expect(returned.orderNumber).toBe(start.orderNumber);
        expect(['committed', 'fulfilled']).toContain(returned.contractState);
    });

    test('a failed payment closes the contract and the order through the webhook; return says failed', async ({ page, request }) => {
        const token = await login(request);
        const basketId = await basketWithOneProduct(request, token);
        const start = await startMollieCheckout(request, token, basketId, 'paypal');

        await payOnMollieTestPage(page, start.redirectUrl, 'Failed');
        await page.waitForURL(/headless=mollie-return/, { timeout: 60_000 });

        const state = await waitForContractToLeave(start.contractId, ['pending', 'not_finished']);
        expect(state).toBe('failed');
        // the failed webhook marks the order FAILED (OxidContractLinkedOrderUpdater); a cancel stornoes it
        expect(orderOf(start.contractId).transStatus).toBe('FAILED');

        const returned = dataOf(
            await gql(request, `mutation { mollieCheckoutReturn(contractId: "${start.contractId}", contractToken: "${start.contractToken}") { status contractState } }`, token),
            'mollieCheckoutReturn',
        );
        expect(returned.status).toBe('failed');
    });

    test('cancel retires an unpaid attempt; a wrong token is refused; method hint reaches Mollie', async ({ request }) => {
        const token = await login(request);
        const basketId = await basketWithOneProduct(request, token);
        const start = await startMollieCheckout(request, token, basketId, 'ideal');
        expect(start.redirectUrl, 'the method hint pins Mollie to iDEAL').toMatch(/ideal/i);

        const refused = await gql(request, `mutation { mollieCheckoutCancel(contractId: "${start.contractId}", contractToken: "0000000000000000000000000000dead") { cancelled } }`, token);
        expect(refused.errors?.[0]?.extensions?.errorCode).toBe('invalid_token');
        expect(contractState(start.contractId)).toBe('pending');

        const cancelled = dataOf(
            await gql(request, `mutation { mollieCheckoutCancel(contractId: "${start.contractId}", contractToken: "${start.contractToken}") { cancelled contractState } }`, token),
            'mollieCheckoutCancel',
        );
        expect(cancelled.cancelled).toBe(true);
        expect(cancelled.contractState).toBe('cancelled');
        expect(orderOf(start.contractId).storno).toBe('1');

        const returned = await gql(request, `mutation { mollieCheckoutReturn(contractId: "${start.contractId}", contractToken: "${start.contractToken}") { status contractState } }`, token);
        const answer = returned.data?.mollieCheckoutReturn;
        expect(answer ? answer.status : returned.errors?.[0]?.message, 'a cancelled attempt cannot be committed').toMatch(/failed|cancelled/i);
    });

    test('core placeOrder is refused for a Mollie basket and points at the mutation', async ({ request }) => {
        const token = await login(request);
        const basketId = await basketWithOneProduct(request, token);
        dataOf(await gql(request, `mutation { basketSetDeliveryMethod(basketId: "${basketId}", deliveryMethodId: "oxidstandard") { id } }`, token), 'basketSetDeliveryMethod');
        dataOf(await gql(request, `mutation { basketSetPayment(basketId: "${basketId}", paymentId: "oe_payments_mollie") { id } }`, token), 'basketSetPayment');

        const refused = await gql(request, `mutation { placeOrder(basketId: "${basketId}", confirmTermsAndConditions: true) { id } }`, token);

        expect(refused.errors?.[0]?.message).toContain('mollieCheckoutStart');
    });
});
