import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/*
 * Réservoir d'une liste personnalisée. On en tire une copie des éléments (le réservoir
 * ne bouge pas) pour la déposer dans la liste, qui se charge de l'ajout (sortable#add).
 */
export default class extends Controller {
    static values = { group: String };

    connect() {
        this.sortable = Sortable.create(this.element, {
            group: { name: this.groupValue, pull: 'clone', put: false },
            sort: false,
            handle: '[data-sortable-handle]',
            animation: 150,
        });
    }

    disconnect() {
        this.sortable.destroy();
    }
}
