import { Controller } from '@hotwired/stimulus';

/* Copie un lien de partage dans le presse-papiers et le confirme sur le bouton. */
export default class extends Controller {
    static values = { text: String };
    static targets = ['button'];

    async copy() {
        const label = this.buttonTarget.textContent;
        try {
            await navigator.clipboard.writeText(this.textValue);
            this.buttonTarget.textContent = 'Lien copié';
        } catch (error) {
            this.buttonTarget.textContent = 'Copie impossible, sélectionne le lien';
        }
        setTimeout(() => { this.buttonTarget.textContent = label; }, 2000);
    }
}
