import { Controller } from '@hotwired/stimulus';

/*
 * Soumet le formulaire de recherche pendant la frappe, après une courte pause.
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 250 } };

    submit() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.element.requestSubmit(), this.delayValue);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }
}
