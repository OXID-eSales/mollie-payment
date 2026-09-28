import { Controller } from '@hotwired/stimulus'
import { createDebugLogger } from '../debug.js'

/**
 * MOL-11 — "Order now" stays inactive until every agreement checkbox on the order step is ticked.
 *
 * Apex renders the agreement checkboxes (`#checkAgbTop` for the AGB when `blConfirmAGB` is on, plus
 * the downloadable / intangible product agreements when the basket needs them — all in
 * `page/checkout/inc/agb.html.twig`) inside a form of their own and the order button outside it;
 * `agb.js` mirrors each tick into the hidden fields the order form posts. Core's
 * `validateTermsAndConditions()` requires all of them, so this gate treats every rendered agreement
 * checkbox alike: the button is enabled exactly while all of them are checked.
 *
 * This is shopper-facing UX, not the safety net: `MollieOrderController::execute()` re-runs the core
 * validation and re-renders the order step with `READ_AND_CONFIRM_TERMS` when the form arrives
 * without consent (a page without this bundle still submits and is bounced there).
 *
 * Usage (views/twig/.../page/checkout/order.html.twig, both Mollie order buttons):
 *   <button data-controller="mollie-place-order mollie-agb-gate" …>   (classic redirect flow)
 *   <button data-controller="mollie-agb-gate" data-action="click->mollie-components#placeOrder" …>
 *
 * Stands down — leaves the button exactly as the server rendered it — when no agreement checkbox is
 * on the page (`blConfirmAGB` off, PsLogin, nothing to agree to) and when the server rendered the
 * button disabled for a reason of its own (low order price), so it never enables what the shop
 * disabled.
 */
export default class extends Controller {
  static values = {
    // Every agreement checkbox Apex may render on the order step; all must be ticked.
    agreements: {
      type: String,
      default: '#checkAgbTop, #oxdownloadableproductsagreement, #oxserviceproductsagreement',
    },
    debug: { type: Boolean, default: false },
  }

  connect() {
    this._debug = createDebugLogger(() => this.debugValue)
    this._checkboxes = []
    if (this.element.disabled) {
      this._debug('AGB gate: button disabled by the server — standing down')
      return
    }
    this._checkboxes = Array.from(document.querySelectorAll(this.agreementsValue))
    if (this._checkboxes.length === 0) {
      this._debug('AGB gate: no agreement checkbox on the page — nothing to gate on')
      return
    }
    this._onChange = () => this.sync()
    this._checkboxes.forEach((checkbox) => checkbox.addEventListener('change', this._onChange))
    this.sync()
  }

  disconnect() {
    this._checkboxes.forEach((checkbox) => checkbox.removeEventListener('change', this._onChange))
    this._checkboxes = []
  }

  /** Enabled exactly while every agreement checkbox is ticked. */
  sync() {
    const accepted = this._checkboxes.every((checkbox) => checkbox.checked)
    this.element.disabled = !accepted
    this._debug('AGB gate: agreements ' + (accepted ? 'accepted — button active' : 'missing — button inactive'))
  }
}
