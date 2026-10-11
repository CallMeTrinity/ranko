import { Controller } from '@hotwired/stimulus';

/*
 * Répondre au duel au clic ou au clavier (flèche gauche ou droite). Au premier envoi,
 * on marque la face choisie pour l'animation et on désactive les deux boutons pour
 * éviter de répondre deux fois à la même question.
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

    pick(event) {
        if (this.element.dataset.duelPicked) {
            event.preventDefault();
            return;
        }
        this.element.dataset.duelPicked = event.submitter === this.leftTarget ? 'left' : 'right';
        this.leftTarget.disabled = true;
        this.rightTarget.disabled = true;
    }

    onKeydown(event) {
        if (event.repeat || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }
        const target = { ArrowLeft: this.leftTarget, ArrowRight: this.rightTarget }[event.key];
        if (target && !target.disabled) {
            event.preventDefault();
            target.form.requestSubmit(target);
        }
    }
}
