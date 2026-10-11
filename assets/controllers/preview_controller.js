import { Controller } from '@hotwired/stimulus';

/*
 * Bouton lecture/pause d'un extrait de 30 s. Un seul lecteur pour toute la page :
 * lancer un extrait coupe celui en cours. La source est une route de l'app qui
 * redirige vers l'extrait ; si le titre n'en a pas, elle répond 404 et le lecteur
 * lève "error", le bouton passe alors en indisponible.
 */
let audio = null;
let owner = null;

const ICONS = { idle: '▶', loading: '…', playing: '❚❚', unavailable: '–' };

function player() {
    if (audio) {
        return audio;
    }
    audio = new Audio();
    audio.preload = 'none';
    audio.addEventListener('playing', () => owner?.render('playing'));
    audio.addEventListener('waiting', () => owner?.render('loading'));
    // Changer de source met en pause l'élément, mais l'événement arrive après le play()
    // du nouvel extrait : paused est alors faux et le nouveau bouton reste en chargement.
    audio.addEventListener('pause', () => audio.paused && owner?.render('idle'));
    audio.addEventListener('ended', () => owner?.render('idle'));
    audio.addEventListener('error', () => {
        owner?.render('unavailable');
        owner = null;
    });
    return audio;
}

export default class extends Controller {
    static values = { url: String, name: String };

    connect() {
        this.render('idle');
    }

    disconnect() {
        // Turbo remplace la page : l'extrait ne doit pas continuer sur la suivante.
        if (owner === this) {
            audio.pause();
            audio.removeAttribute('src');
            owner = null;
        }
    }

    toggle() {
        const audio = player();
        if (owner === this && !audio.paused) {
            audio.pause();
            return;
        }
        if (owner !== this) {
            owner?.render('idle');
            owner = this;
            audio.src = this.urlValue;
        }
        this.render('loading');
        // Les échecs de chargement passent par l'événement "error" ; le rejet restant
        // vient d'un changement de source pendant le chargement, sans conséquence.
        audio.play().catch(() => {});
    }

    render(state) {
        this.element.textContent = ICONS[state];
        this.element.disabled = state === 'unavailable';
        this.element.setAttribute('aria-pressed', String(state === 'playing' || state === 'loading'));
        this.element.setAttribute('aria-label', state === 'unavailable'
            ? `Pas d'extrait disponible pour ${this.nameValue}`
            : `Écouter un extrait de ${this.nameValue}`);
        this.element.title = state === 'unavailable' ? 'Pas d\'extrait disponible' : 'Écouter un extrait';
    }
}
