/**
 * Read-only access to Mollie's API for diagnostics (the shop's test key, passed as MOLLIE_API_KEY).
 * Used to compare what Mollie says about a payment with what the shop stored and the admin shows.
 */
export interface MolliePaymentView {
    id: string;
    status: string;
    method: string | null;
    amount: string;
    amountRemaining: string | null;
    amountRefunded: string | null;
    amountCaptured: string | null;
    settlementAmount: string | null;
    captureMode: string | null;
    hasRefundsLink: boolean;
    detailKeys: string[];
}

export async function fetchMolliePayment(paymentId: string): Promise<MolliePaymentView | { error: string }> {
    const key = process.env.MOLLIE_API_KEY;
    if (!key) {
        return { error: 'MOLLIE_API_KEY not set' };
    }
    const response = await fetch(`https://api.mollie.com/v2/payments/${encodeURIComponent(paymentId)}`, {
        headers: { Authorization: `Bearer ${key}` },
    });
    if (!response.ok) {
        return { error: `HTTP ${response.status}` };
    }
    const p = await response.json();
    const money = (m: { value: string; currency: string } | undefined | null) => (m ? `${m.value} ${m.currency}` : null);
    return {
        id: p.id,
        status: p.status,
        method: p.method ?? null,
        amount: money(p.amount) ?? '',
        amountRemaining: money(p.amountRemaining),
        amountRefunded: money(p.amountRefunded),
        amountCaptured: money(p.amountCaptured),
        settlementAmount: money(p.settlementAmount),
        captureMode: p.captureMode ?? null,
        hasRefundsLink: Boolean(p._links?.refunds),
        detailKeys: Object.keys(p.details ?? {}),
    };
}
