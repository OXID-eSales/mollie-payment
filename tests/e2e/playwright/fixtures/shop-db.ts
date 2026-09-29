import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';

/**
 * Read-only access to the shop database of the local Docker SDK stack the e2e suite tunnels to.
 *
 * MOL-18: "one order per checkout attempt" is a statement about `oxorder` rows, and the admin
 * order list is not a reliable way to count them (a cancelled/storno'd row and a row without a
 * payment type both render in the list). The suite runs on the same host as the docker stack
 * (SHOP_URL is a tunnel to it), so `docker compose exec mysql` is the shortest honest path to
 * the table. Configure with:
 *
 *   SHOP_DOCKER_COMPOSE_DIR  directory holding docker-compose.yml (default: six levels above
 *                            this playwright project, i.e. the Docker SDK root)
 *   SHOP_DB_NAME / SHOP_DB_USER / SHOP_DB_PASSWORD   default example / root / root (.env.dist)
 */
const COMPOSE_DIR = process.env.SHOP_DOCKER_COMPOSE_DIR || path.resolve(process.cwd(), '../../../../../..');
const DB_NAME = process.env.SHOP_DB_NAME || 'example';
const DB_USER = process.env.SHOP_DB_USER || 'root';
const DB_PASSWORD = process.env.SHOP_DB_PASSWORD || 'root';

export interface OrderRow {
    oxid: string;
    orderNr: string;
    paymentType: string;
    storno: string;
    transStatus: string;
    totalOrderSum: string;
    articleCount: number;
    userId: string;
}

export function shopDbQuery(sql: string): string[][] {
    if (!existsSync(path.join(COMPOSE_DIR, 'docker-compose.yml'))) {
        throw new Error(`shop-db: no docker-compose.yml in ${COMPOSE_DIR} — set SHOP_DOCKER_COMPOSE_DIR`);
    }
    const out = execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'mysql', 'mysql', `-u${DB_USER}`, `-p${DB_PASSWORD}`, '-N', '-B', DB_NAME, '-e', sql],
        { cwd: COMPOSE_DIR, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
    );
    return out
        .split('\n')
        .filter((line) => line !== '')
        .map((line) => line.split('\t'));
}

/**
 * Statement without a result set (UPDATE …). MOL-15 uses it to plant and repair an invalid
 * character in the e2e user's address; specs restore the original in `finally`.
 */
export function shopDbExecute(sql: string): void {
    shopDbQuery(sql);
}

/** The e2e user's billing street, read back for restoration. */
export function billingStreetOf(email: string): string {
    return shopDbQuery(`SELECT OXSTREET FROM oxuser WHERE OXUSERNAME = '${email.replace(/'/g, "''")}'`)[0]?.[0] ?? '';
}

export function setBillingStreetOf(email: string, street: string): void {
    shopDbExecute(
        `UPDATE oxuser SET OXSTREET = '${street.replace(/'/g, "''")}' WHERE OXUSERNAME = '${email.replace(/'/g, "''")}'`,
    );
}

/** Every `oxorder.OXID` currently in the table — the baseline to diff a checkout against. */
export function allOrderIds(): Set<string> {
    return new Set(shopDbQuery('SELECT OXID FROM oxorder').map((r) => r[0]));
}

/** Orders that did not exist in `baseline`, with the fields the MOL-18 assertions need. */
export function ordersAddedSince(baseline: Set<string>): OrderRow[] {
    const rows = shopDbQuery(
        'SELECT o.OXID, o.OXORDERNR, o.OXPAYMENTTYPE, o.OXSTORNO, o.OXTRANSSTATUS, o.OXTOTALORDERSUM, ' +
            '(SELECT COUNT(*) FROM oxorderarticles a WHERE a.OXORDERID = o.OXID), o.OXUSERID FROM oxorder o',
    );
    return rows
        .filter((r) => !baseline.has(r[0]))
        .map((r) => ({
            oxid: r[0],
            orderNr: r[1],
            paymentType: r[2],
            storno: r[3],
            transStatus: r[4],
            totalOrderSum: r[5],
            articleCount: Number(r[6]),
            userId: r[7],
        }));
}

/** Rows with no payment type: the signature of a never-loaded Order saved on ORDER_STATE_ORDEREXISTS. */
export function phantomOrderCount(): number {
    return Number(shopDbQuery("SELECT COUNT(*) FROM oxorder WHERE OXPAYMENTTYPE = ''")[0][0]);
}

/**
 * MOL-11: the AGB confirmation switch (`blConfirmAGB`, shop 1). Specs that prove the "Order now"
 * gate set it explicitly — on to see the checkbox, off to see the button active without one —
 * and restore the original value in `finally`. OXID CE reads `oxconfig` on every request, so no
 * cache clear is needed for the change to reach the storefront.
 */
export function confirmAgbEnabled(): boolean {
    return (shopDbQuery(`SELECT OXVARVALUE FROM oxconfig WHERE OXSHOPID = '1' AND OXVARNAME = 'blConfirmAGB'`)[0]?.[0] ?? '') === '1';
}

export function setConfirmAgbEnabled(enabled: boolean): void {
    shopDbExecute(`UPDATE oxconfig SET OXVARVALUE = '${enabled ? '1' : '0'}' WHERE OXSHOPID = '1' AND OXVARNAME = 'blConfirmAGB'`);
}

/**
 * MOL-22: an article's stock and stock flag (`oxarticles`, shop-wide). Specs make a basket item
 * "not orderable" after the order step rendered (flag 3 = not orderable when out of stock; stock 0
 * turns the item unbuyable, stock 1 with two ordered fails core's finalizeOrder() stock check) and
 * restore the original values in `finally`.
 */
export interface ArticleStock {
    stock: number;
    flag: number;
}

export function articleStockOf(articleId: string): ArticleStock {
    const row = shopDbQuery(`SELECT OXSTOCK, OXSTOCKFLAG FROM oxarticles WHERE OXID = '${articleId}'`)[0];
    if (!row) {
        throw new Error(`shop-db: no article ${articleId}`);
    }
    return { stock: Number(row[0]), flag: Number(row[1]) };
}

export function setArticleStock(articleId: string, stock: ArticleStock): void {
    shopDbExecute(`UPDATE oxarticles SET OXSTOCK = ${stock.stock}, OXSTOCKFLAG = ${stock.flag} WHERE OXID = '${articleId}'`);
}
