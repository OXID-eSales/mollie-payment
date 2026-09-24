import { Controller } from '@hotwired/stimulus'

/**
 * MOL-18 — the classic "Order now" button (redirect flow, inline card disabled) submits once.
 *
 * Apex renders `<button onclick="document.getElementById('orderConfirmAgbBottom').submit();">`:
 * every click is a full POST to cl=order&fnc=execute, and a shopper's two quick clicks reach PHP
 * one after the other. The server rejoins the attempt already in flight since MOL-18, so this is
 * UX, not the safety net: the button locks itself and shows a loading state after the first click.
 *
 * Usage (views/twig/.../page/checkout/order.html.twig, Mollie + classic flow only):
 *   <button type="button" data-controller="mollie-place-order"
 *           data-action="click->mollie-place-order#submit"
 *           onclick="document.getElementById('orderConfirmAgbBottom').submit();">
 *
 * The inline `onclick` is apex's own handler and stays in the markup as the no-bundle fallback;
 * once this controller connects it takes the click over and removes it, so a click never submits
 * twice in one tick.
 */
export default class extends Controller {
  static values = {
    // Id of the core order form (index.php?cl=order&fnc=execute).
    formId: { type: String, default: 'orderConfirmAgbBottom' },
  }

  connect() {
    this._inFlight = false
    this.element.removeAttribute('onclick')
    // Browser back from Mollie restores this page from the bfcache with its JS state intact:
    // the lock must open again or the shopper cannot order at all.
    this._onPageShow = (event) => {
      if (event.persisted) {
        this._release()
      }
    }
    window.addEventListener('pageshow', this._onPageShow)
  }

  disconnect() {
    window.removeEventListener('pageshow', this._onPageShow)
  }

  submit(event) {
    if (event) {
      event.preventDefault()
    }
    if (this._inFlight) {
      return
    }
    const form = document.getElementById(this.formIdValue)
    if (!form) {
      return
    }
    this._inFlight = true
    this.element.disabled = true
    this.element.classList.add('is-loading')
    // Apex parity: plain submit(), no constraint validation - the server re-renders the AGB error.
    form.submit()
  }

  _release() {
    this._inFlight = false
    this.element.disabled = false
    this.element.classList.remove('is-loading')
  }
}
