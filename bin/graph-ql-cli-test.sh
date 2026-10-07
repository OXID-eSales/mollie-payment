#!/usr/bin/env bash
#
# graph-ql-cli-test.sh — drive the headless Mollie checkout through the
# GraphQL Storefront from the command line (GRAPH-QL / P-Mollie, MS6).
#
# Shows what the Mollie module can do without a Twig page: open a contract
# with mollieCheckoutStart (Mollie's hosted page, optional method hint),
# let the webhook end the order after the shopper paid on Mollie, read the
# state with mollieCheckoutReturn, retire an attempt with mollieCheckoutCancel,
# see a wrong token refused, and see the core placeOrder refused for a basket
# that pays with Mollie.
#
# Docs and examples: bin/graph-ql-cli-test.md
# Needs: curl, jq. A shop with oe_graphql_base + oe_graphql_storefront,
# payment-base and this module active, Mollie test API key + profile configured.
#
set -euo pipefail

GRAPHQL_URL="${GRAPHQL_URL:-http://localhost.local/graphql/}"
SHOP_URL="${SHOP_URL:-$(printf '%s' "$GRAPHQL_URL" | sed -E 's#(https?://[^/]+).*#\1/#')}"
USER_EMAIL="${USER_EMAIL:-headless.user@oxid-esales.dev}"
USER_PASSWORD="${USER_PASSWORD:-useruser}"
PRODUCT_ID="${PRODUCT_ID:-5e6a374e212258abbfd76b6adf911772}"   # "Panorama", 20.90 EUR in the demo data
DELIVERY_METHOD_ID="${DELIVERY_METHOD_ID:-oxidstandard}"
STATE_FILE="${STATE_FILE:-${TMPDIR:-/tmp}/graph-ql-cli-test-mollie.state}"
# The return / cancel URLs belong to the headless client; the shop does not
# serve them. For a test by hand the shop's start page is a friendlier landing
# than a 404: we append &contract_id=... to it (Mollie appends nothing).
RETURN_URL="${RETURN_URL:-${SHOP_URL}index.php?cl=start&headless=return}"
# Mollie knows one redirect URL for every outcome; the mutation accepts cancelUrl for symmetry only.
CANCEL_URL="${CANCEL_URL:-${SHOP_URL}index.php?cl=start&headless=cancel}"
MOLLIE_METHOD="${MOLLIE_METHOD:-}"   # e.g. ideal, creditcard, klarna; empty = Mollie's page offers every method
# OXID empties the basket for user agents it takes for search engines (curl's
# default is one). A real headless client is a browser or an app; look like one.
USER_AGENT="${USER_AGENT:-Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36 graph-ql-cli-test}"
VERBOSE="${VERBOSE:-0}"

TOKEN=""

usage() {
    cat <<USAGE
usage: $(basename "$0") <command> [args]

  schema                  the Mollie mutations and their result types, as the schema exposes them
  start [method]          login, create a basket with one product, mollieCheckoutStart (method hint optional)
  pay [method]            = start, then tells you how to pay on Mollie's test page and what ends the order
  return [contractId contractToken]
                          mollieCheckoutReturn — reports (or, without a webhook, commits) the contract
  cancel [contractId contractToken]
                          mollieCheckoutCancel — retires the unpaid contract (ids default to the last start)
  wrong-token             mollieCheckoutCancel with a bogus token: refused, nothing changes
  guard                   core placeOrder on a basket paying with Mollie: refused, names mollieCheckoutStart
  demo                    everything that needs no browser: schema, start (+ ideal), cancel, wrong-token, guard
  raw '<query>'           any GraphQL document, logged in (for your own experiments)

environment (defaults in brackets):
  GRAPHQL_URL [$GRAPHQL_URL]   SHOP_URL [$SHOP_URL]
  USER_EMAIL [$USER_EMAIL]   USER_PASSWORD [***]
  PRODUCT_ID [$PRODUCT_ID]   DELIVERY_METHOD_ID [$DELIVERY_METHOD_ID]   MOLLIE_METHOD [${MOLLIE_METHOD:-<none>}]
  RETURN_URL (must be under an allowed origin; the shop's own origin always is; Mollie appends nothing, we append contract_id)
  STATE_FILE [$STATE_FILE]   VERBOSE=1 prints every raw response
USAGE
}

need() { command -v "$1" >/dev/null 2>&1 || { echo "missing: $1" >&2; exit 2; }; }
# Headings and notes go to stderr so that functions returning a value via
# stdout (basket_with_one_product) can still talk.
say() { printf '\n\033[1m%s\033[0m\n' "$*" >&2; }
note() { printf '  %s\n' "$*" >&2; }

# gql '<document>' — POST to the endpoint, Bearer token when logged in. Prints the JSON body.
gql() {
    local body
    body=$(jq -n --arg q "$1" '{query: $q}')
    local -a auth=()
    [ -n "$TOKEN" ] && auth=(-H "Authorization: Bearer $TOKEN")
    local response
    response=$(curl -sS "$GRAPHQL_URL" -A "$USER_AGENT" -H 'Content-Type: application/json' "${auth[@]}" --data-binary "$body")
    [ "$VERBOSE" = "1" ] && printf '%s\n' "$response" | jq . >&2
    printf '%s' "$response"
}

# data '<json>' '<field>' — the field's data, or exit with the GraphQL error.
data() {
    local errors
    errors=$(printf '%s' "$1" | jq -c '.errors // empty')
    if [ -n "$errors" ]; then
        echo "GraphQL error: $(error_of "$1")" >&2
        case "$(printf '%s' "$1" | jq -r '.errors[0].extensions.errorCode // ""')" in
            return_url_rejected) echo "hint: RETURN_URL / CANCEL_URL must be under the shop's own URL or an origin in the setting sPaymentBaseHeadlessReturnOrigins — set SHOP_URL=https://<your shop>/" >&2 ;;
        esac
        exit 1
    fi
    printf '%s' "$1" | jq -c ".data.$2"
}

# error_of '<json>' — "message [errorCode/providerCode]" of a failed response.
# Headless refusals carry a stable extensions.errorCode (payment-base
# HeadlessCheckoutException) and, when the provider refused, a providerCode.
error_of() {
    printf '%s' "$1" | jq -r 'if .errors then "\(.errors[0].message) [\(.errors[0].extensions.errorCode // "-")\(if .errors[0].extensions.providerCode then "/" + .errors[0].extensions.providerCode else "" end)]" else "NO ERROR — unexpected: \(.data|tojson)" end'
}

login() {
    [ -n "$TOKEN" ] && return
    TOKEN=$(data "$(gql "{ token(username: \"$USER_EMAIL\", password: \"$USER_PASSWORD\") }")" token | jq -r .)
    note "logged in as $USER_EMAIL"
}

basket_with_one_product() {
    local basket_id total
    basket_id=$(data "$(gql "mutation { basketCreate(basket: {title: \"graph-ql-cli-$(date +%s%N)\", public: false}) { id } }")" basketCreate.id | jq -r .)
    total=$(data "$(gql "mutation { basketAddItem(basketId: \"$basket_id\", productId: \"$PRODUCT_ID\", amount: 1) { cost { total } } }")" basketAddItem.cost.total)
    if [ "$total" = "0" ] || [ "$total" = "null" ]; then
        echo "basket total is $total — the shop took this client for a bot (user agent) or the product is not orderable" >&2
        exit 1
    fi
    note "basket $basket_id, total $total"
    printf '%s' "$basket_id"
}

save_state() { printf 'CONTRACT_ID=%s\nCONTRACT_TOKEN=%s\nBASKET_ID=%s\n' "$1" "$2" "$3" > "$STATE_FILE"; }
load_state() {
    CONTRACT_ID="${1:-}"; CONTRACT_TOKEN="${2:-}"
    if [ -z "$CONTRACT_ID" ] && [ -f "$STATE_FILE" ]; then
        # shellcheck disable=SC1090
        . "$STATE_FILE"
        note "using the last start: contract $CONTRACT_ID"
    fi
    [ -n "${CONTRACT_ID:-}" ] && [ -n "${CONTRACT_TOKEN:-}" ] || { echo "no contract: run 'start' first or pass <contractId> <contractToken>" >&2; exit 1; }
}

cmd_schema() {
    say "Mollie mutations in the schema (logged in — GraphQLite hides #[Logged] fields from anonymous introspection)"
    login
    gql '{ __type(name: "Mutation") { fields { name args { name type { name kind ofType { name } } } type { name ofType { name } } } } }' \
        | jq -r '.data.__type.fields[] | select(.name|startswith("mollieCheckout")) | "  \(.name)(\([.args[] | "\(.name): \(.type.name // .type.ofType.name)"] | join(", "))): \(.type.name // .type.ofType.name)"'
    for type in CheckoutStartResult CheckoutReturnResult CheckoutCancelResult; do
        printf '  %s { %s }\n' "$type" "$(gql "{ __type(name: \"$type\") { fields { name type { name ofType { name } } } } }" | jq -r '[.data.__type.fields[] | "\(.name): \(.type.name // .type.ofType.name)"] | join(", ")')"
    done
}

cmd_start() {
    local method="${1:-$MOLLIE_METHOD}"
    say "mollieCheckoutStart${method:+ (method: $method)}"
    login
    local basket_id start method_arg=""
    basket_id=$(basket_with_one_product)
    [ -n "$method" ] && method_arg=", method: \"$method\""
    start=$(data "$(gql "mutation { mollieCheckoutStart(basketId: \"$basket_id\", confirmTermsAndConditions: true, returnUrl: \"$RETURN_URL\", cancelUrl: \"$CANCEL_URL\"$method_arg) { contractId contractToken providerName orderNumber redirectUrl clientSecret renderMode } }")" mollieCheckoutStart)
    printf '%s\n' "$start" | jq .
    save_state "$(printf '%s' "$start" | jq -r .contractId)" "$(printf '%s' "$start" | jq -r .contractToken)" "$basket_id"
    note "contract + order are open (order NOT_FINISHED); saved to $STATE_FILE"
}

cmd_pay() {
    cmd_start "${1:-}"
    say "Now pay"
    note "1. open the redirectUrl above in a browser: Mollie's TEST page lets you pick the outcome (Paid, Failed, Canceled, Expired;"
    note "   for a card/Klarna with manual capture: Authorized). Pick one."
    note "2. Mollie's webhook ends the order: paid -> committed + paid; authorized -> committed, capture later in the admin;"
    note "   failed/canceled/expired -> contract closed, order cancelled. Mollie then sends the browser to"
    note "   ${RETURN_URL}&contract_id=<contractId>."
    note "3. run: $(basename "$0") return   — it reports the state the webhook left (or commits itself if no webhook arrived)"
}

cmd_return() {
    load_state "${1:-}" "${2:-}"
    say "mollieCheckoutReturn"
    login
    data "$(gql "mutation { mollieCheckoutReturn(contractId: \"$CONTRACT_ID\", contractToken: \"$CONTRACT_TOKEN\") { status orderId orderNumber contractState } }")" mollieCheckoutReturn | jq .
}

cmd_cancel() {
    load_state "${1:-}" "${2:-}"
    say "mollieCheckoutCancel"
    login
    data "$(gql "mutation { mollieCheckoutCancel(contractId: \"$CONTRACT_ID\", contractToken: \"$CONTRACT_TOKEN\") { cancelled contractState } }")" mollieCheckoutCancel | jq .
}

cmd_wrong_token() {
    load_state "${1:-}" ""
    CONTRACT_TOKEN="0000000000000000000000000000dead"
    say "mollieCheckoutCancel with a wrong token (expected: refused)"
    login
    note "$(error_of "$(gql "mutation { mollieCheckoutCancel(contractId: \"$CONTRACT_ID\", contractToken: \"$CONTRACT_TOKEN\") { cancelled contractState } }")")"
}

cmd_guard() {
    say "core placeOrder on a basket that pays with Mollie (expected: refused, points at mollieCheckoutStart)"
    login
    local basket_id
    basket_id=$(basket_with_one_product)
    data "$(gql "mutation { basketSetDeliveryMethod(basketId: \"$basket_id\", deliveryMethodId: \"$DELIVERY_METHOD_ID\") { id } }")" basketSetDeliveryMethod.id >/dev/null
    data "$(gql "mutation { basketSetPayment(basketId: \"$basket_id\", paymentId: \"oe_payments_mollie\") { id } }")" basketSetPayment.id >/dev/null
    note "$(error_of "$(gql "mutation { placeOrder(basketId: \"$basket_id\", confirmTermsAndConditions: true) { id } }")")"
}

cmd_demo() {
    cmd_schema
    cmd_start ""
    cmd_return
    cmd_cancel
    cmd_start ideal
    cmd_wrong_token
    cmd_cancel
    cmd_guard
    say "Done. For a paid order run: $(basename "$0") pay"
}

cmd_raw() {
    [ -n "${1:-}" ] || { echo "usage: raw '<graphql document>'" >&2; exit 1; }
    login
    gql "$1" | jq .
}

need curl; need jq
case "${1:-}" in
    schema) cmd_schema ;;
    start) cmd_start "${2:-}" ;;
    pay) cmd_pay "${2:-}" ;;
    return) cmd_return "${2:-}" "${3:-}" ;;
    cancel) cmd_cancel "${2:-}" "${3:-}" ;;
    wrong-token) cmd_wrong_token "${2:-}" ;;
    guard) cmd_guard ;;
    demo) cmd_demo ;;
    raw) cmd_raw "${2:-}" ;;
    -h|--help|help|"") usage ;;
    *) echo "unknown command: $1" >&2; usage >&2; exit 2 ;;
esac
