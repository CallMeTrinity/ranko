import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/*
 * Réordonne une liste par glisser-déposer (SortableJS) ou avec les boutons ↑/↓,
 * puis envoie l'ordre complet au serveur. Le serveur refuse tout ce qui n'est pas
 * une permutation exacte : en cas d'erreur, on recharge pour revenir à l'état réel.
 */
export default class extends Controller {
    static values = { url: String, token: String };

    connect() {
        this.status = document.querySelector('[data-sortable-status]');
        this.sortable = Sortable.create(this.element, {
            handle: '[data-sortable-handle]',
            animation: 150,
            onEnd: (event) => {
                if (event.oldIndex !== event.newIndex) {
                    this.save();
                }
            },
        });
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
