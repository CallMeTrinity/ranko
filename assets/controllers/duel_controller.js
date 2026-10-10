import { Controller } from '@hotwired/stimulus';

/*
 * Répondre au duel au clavier : flèche gauche ou droite. Le bouton est désactivé
 * après l'envoi pour éviter de répondre deux fois à la même question.
 */
export default class extends Controller {
    static targets = ['left', 'right'];

    connect() {
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    onKeydown(event) {
        if (event.repeat || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }
        const target = { ArrowLeft: this.leftTarget, ArrowRight: this.rightTarget }[event.key];
        if (target && !target.disabled) {
            event.preventDefault();
            target.form.requestSubmit(target);
            this.leftTarget.disabled = true;
            this.rightTarget.disabled = true;
        }
    }
}
