/**
 * Mollie Module — shared debug logger utility.
 *
 * Mirrors Stripe's `resources/build/js/debug.js` (Phase 5 lesson): production esbuild strips
 * literal `console.log(...)` calls via the `pure` option, but intentional diagnostics should
 * still be reachable behind a runtime flag without a redeploy. Routing them through an aliased
 * `consoleRef.log(...)` call (not the literal `console.log(...)` text) means esbuild's `pure`
 * pass cannot statically match and strip it, while the `isEnabled()` guard still silences it by
 * default in production. `console.error(...)` is never wrapped — genuine failures always log.
 *
 * @param {() => boolean} isEnabled - Returns true when debug output is wanted.
 * @returns {(...args: unknown[]) => void}
 */
const consoleRef = globalThis.console

export function createDebugLogger(isEnabled) {
  return function debug(...args) {
    if (!isEnabled()) {
      return
    }
    consoleRef.log(...args)
  }
}
