import { Controller } from '@hotwired/stimulus'
import { createDebugLogger } from '../debug.js'

/**
 * MOL-11 / MOL-9 — the Mollie order block stays locked until every agreement checkbox on the order
 * step is ticked.
 *
 * Apex renders the agreement checkboxes (`#checkAgbTop` for the AGB when `blConfirmAGB` is on, plus
 * the downloadable / intangible product agreements when the basket needs them — all in
 * `page/checkout/inc/agb.html.twig`) inside a form of their own and the Mollie block outside it;
 * `agb.js` mirrors each tick into the hidden fields the order form posts. Core's
 * `validateTermsAndConditions()` requires all of them, so this gate treats every rendered agreement
 * checkbox alike: the block is unlocked exactly while all of them are checked.
 *
 * Two shapes, one rule:
 *  - on the inline-Components wrapper (MOL-9):
 *      <div data-controller="mollie-components mollie-agb-gate">
 *        <div data-mollie-agb-gate-target="region">  method radios + card fields  </div>
 *        <button data-mollie-agb-gate-target="button" …>
 *    the `region` gets the `inert` attribute (no pointer, no keyboard, no focus — the Mollie card
 *    iframes included) plus the class `mollie-agb-locked` (dims it; `pointer-events: none` as the
 *    fallback for browsers without `inert`), the `button` gets `disabled`;
 *  - on a lone button (the classic redirect flow, MOL-11):
 *      <button data-controller="mollie-place-order mollie-agb-gate" …>
 *    the element itself gets `disabled`.
 *
 * `inert` does not touch form submission: the checked method radio still rides with the order form.
 * This is shopper-facing UX, not the safety net: `MollieOrderController::execute()` re-runs the core
 * validation and re-renders the order step with `READ_AND_CONFIRM_TERMS` when the form arrives
 * without consent (a page without this bundle still submits and is bounced there).
 *
 * Stands down — leaves everything as the server rendered it — when no agreement checkbox is on the
 * page (`blConfirmAGB` off, PsLogin, nothing to agree to) and when the server rendered the button
 * disabled for a reason of its own (low order price), so it never enables what the shop disabled.
 */
export default class extends Controller {
  static targets = ['button', 'region']
  static values = {
    // Every agreement checkbox Apex may render on the order step; all must be ticked.
    agreements: {
      type: String,
      default: '#checkAgbTop, #oxdownloadableproductsagreement, #oxserviceproductsagreement',
    },
    debug: { type: Boolean, default: false },
  }

  static LOCKED_CLASS = 'mollie-agb-locked'

  connect() {
    this._debug = createDebugLogger(() => this.debugValue)
    this._checkboxes = []
    if (this._buttons().some((button) => button.disabled)) {
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

  /** Unlocked exactly while every agreement checkbox is ticked. */
  sync() {
    const accepted = this._checkboxes.every((checkbox) => checkbox.checked)
    this._buttons().forEach((button) => {
      button.disabled = !accepted
    })
    this._regions().forEach((region) => this._lockRegion(region, !accepted))
    this._debug('AGB gate: agreements ' + (accepted ? 'accepted — block unlocked' : 'missing — block locked'))
  }

  _lockRegion(region, locked) {
    if (locked) {
      region.setAttribute('inert', '')
      region.classList.add(this.constructor.LOCKED_CLASS)
      return
    }
    region.removeAttribute('inert')
    region.classList.remove(this.constructor.LOCKED_CLASS)
  }

  /** The `button` targets on a block; the element itself when the gate sits on a lone button. */
  _buttons() {
    if (this.hasButtonTarget) {
      return this.buttonTargets
    }
    return this.element instanceof HTMLButtonElement ? [this.element] : []
  }

  _regions() {
    return this.hasRegionTarget ? this.regionTargets : []
  }
}
