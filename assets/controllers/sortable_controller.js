import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/*
 * Réordonne une liste par glisser-déposer (SortableJS) ou avec les boutons ↑/↓,
 * puis envoie l'ordre complet au serveur. Le serveur refuse tout ce qui n'est pas
 * une permutation exacte : en cas d'erreur, on recharge pour revenir à l'état réel.
 *
 * Avec un groupe (liste personnalisée), la liste accepte aussi les éléments glissés
 * depuis le réservoir du même groupe, tant qu'elle n'est pas complète.
 */
export default class extends Controller {
    static values = { url: String, token: String, group: String, full: Boolean };

    connect() {
        this.status = document.querySelector('[data-sortable-status]');
        const options = {
            handle: '[data-sortable-handle]',
            animation: 150,
            onEnd: (event) => {
                if (event.oldIndex !== event.newIndex) {
                    this.save();
                }
            },
        };
        if (this.groupValue) {
            options.group = { name: this.groupValue, pull: false, put: () => !this.fullValue };
            options.onAdd = (event) => this.add(event);
        }
        this.sortable = Sortable.create(this.element, options);
    }

    /*
     * Élément déposé depuis le réservoir : on soumet son formulaire d'ajout avec la
     * position de dépôt. Turbo suit la redirection et rafraîchit la page en morph.
     */
    add(event) {
        const form = event.item.querySelector('form');
        const position = form.querySelector('[data-pool-position]');
        position.value = event.newIndex + 1;
        position.disabled = false;
        this.setStatus('Ajout…');
        form.requestSubmit();
    }

    disconnect() {
        this.sortable.destroy();
    }

    moveUp(event) {
        const item = event.currentTarget.closest('[data-item-id]');
        if (item.previousElementSibling) {
            item.previousElementSibling.before(item);
            event.currentTarget.focus();
            this.save();
        }
    }

    moveDown(event) {
        const item = event.currentTarget.closest('[data-item-id]');
        if (item.nextElementSibling) {
            item.nextElementSibling.after(item);
            event.currentTarget.focus();
            this.save();
        }
    }

    async save() {
        const items = [...this.element.querySelectorAll(':scope > [data-item-id]')];
        items.forEach((item, index) => {
            item.querySelector('[data-sortable-position]').textContent = String(index + 1).padStart(2, '0');
        });

        this.setStatus('Enregistrement…');
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify({ itemIds: items.map((item) => Number(item.dataset.itemId)) }),
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.setStatus('Ordre enregistré.');
        } catch (error) {
            this.setStatus('Échec de l\'enregistrement, rechargement…');
            window.location.reload();
        }
    }

    setStatus(message) {
        if (this.status) {
            this.status.textContent = message;
        }
    }
}
