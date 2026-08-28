/*
 * Comportements visuels de Pass241.
 *
 * Trois regles ont guide ce fichier :
 *
 * 1. Aucune dependance. Tout tient en API natives — le poids envoye sur une
 *    connexion mobile gabonaise est un cout reel (section 72).
 * 2. Le contenu est visible par defaut. Ce script ajoute des effets ; il ne
 *    conditionne jamais la lisibilite de la page. S'il ne se charge pas,
 *    l'utilisateur ne voit pas la difference sur ce qui compte.
 * 3. Rien ne s'execute a chaque image sans raison. Les evenements frequents
 *    passent par requestAnimationFrame et les observateurs se debranchent
 *    des qu'ils ont fini leur travail.
 */

const root = document.documentElement;

/* -------------------------------------------------------------------------
 * Theme clair / sombre
 * ---------------------------------------------------------------------- */

/*
 * Le theme est APPLIQUE avant le premier rendu, par un petit script pose
 * directement dans le <head> des gabarits (`x-theme-script`). Ce module-ci
 * n'arrive qu'ensuite : il ne fait que gerer les CHANGEMENTS.
 *
 * L'ordre compte. Ce fichier est charge par Vite, donc en differe : s'il
 * portait aussi l'application initiale, la page s'afficherait en clair puis
 * basculerait en sombre sous les yeux de l'utilisateur. Un ecran blanc d'un
 * dixieme de seconde en pleine nuit se remarque, et se retient.
 *
 * Trois choix possibles, pas deux : « clair », « sombre » et « systeme ».
 * « Systeme » n'est pas un repli faute de choix — c'est un choix a part
 * entiere, celui de suivre le telephone, qui bascule souvent au coucher du
 * soleil. Le retirer obligerait a revenir ici deux fois par jour.
 */

const THEME_KEY = 'pass241:theme';
const systemDark = window.matchMedia('(prefers-color-scheme: dark)');

function storedChoice() {
    try {
        const value = localStorage.getItem(THEME_KEY);
        return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
        // Navigation privee, stockage refuse : on suit le systeme.
        return 'system';
    }
}

function resolveTheme(choice) {
    if (choice === 'light' || choice === 'dark') {
        return choice;
    }
    return systemDark.matches ? 'dark' : 'light';
}

/**
 * Applique un theme et annonce le changement.
 *
 * `data-theme-switching` arme la transition de couleur le temps du fondu, puis
 * disparait. Une transition permanente ferait trainer chaque survol de ligne
 * de tableau : l'interface entiere paraitrait molle (voir la section 6 du CSS).
 */
function applyTheme(choice, { animate = true } = {}) {
    const theme = resolveTheme(choice);

    if (root.dataset.theme === theme) {
        root.dataset.themeChoice = choice;
        return;
    }

    if (animate && !reducedMotion.matches) {
        root.setAttribute('data-theme-switching', '');
        window.setTimeout(() => root.removeAttribute('data-theme-switching'), 260);
    }

    root.dataset.theme = theme;
    root.dataset.themeChoice = choice;

    // La barre d'adresse du navigateur mobile suit le fond de la page. Sans
    // cela, un liseré clair reste colle en haut d'un ecran sombre.
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
        meta.setAttribute('content', theme === 'dark' ? '#0b0f18' : '#f7f9fb');
    }

    document.dispatchEvent(new CustomEvent('pass241:theme', { detail: { theme, choice } }));
}

window.Pass241 = window.Pass241 || {};

window.Pass241.theme = {
    get choice() {
        return root.dataset.themeChoice || storedChoice();
    },
    get resolved() {
        return root.dataset.theme || 'light';
    },
    set(choice) {
        try {
            if (choice === 'system') {
                localStorage.removeItem(THEME_KEY);
            } else {
                localStorage.setItem(THEME_KEY, choice);
            }
        } catch {
            // Le choix ne survivra pas au rechargement, la session reste juste.
        }
        applyTheme(choice);
    },
};

/*
 * Bascule par attribut, pour les pages SANS Alpine.
 *
 * La page d'accueil publique ne charge pas Livewire, donc pas Alpine : le
 * composant `x-ui.theme-toggle` du tableau de bord n'y fonctionnerait pas. Un
 * ecouteur delegue sur le document couvre le cas sans rien dupliquer, et
 * continue de fonctionner si le bouton est ajoute apres coup.
 *
 * L'ETAT VISUEL N'EST PAS GERE ICI : il se lit en CSS depuis `data-theme` sur
 * <html>. Aucune synchronisation a tenir, donc aucune desynchronisation
 * possible.
 */
document.addEventListener('click', (event) => {
    const bouton = event.target.closest?.('[data-theme-toggle]');
    if (!bouton) return;

    window.Pass241.theme.set(root.dataset.theme === 'dark' ? 'light' : 'dark');
});

// Le systeme bascule (coucher du soleil, mode nuit programme) : on ne suit que
// si l'utilisateur n'a pas fait de choix explicite.
systemDark.addEventListener('change', () => {
    if (storedChoice() === 'system') {
        applyTheme('system');
    }
});

// Deux onglets ouverts : le choix fait dans l'un doit valoir dans l'autre.
window.addEventListener('storage', (event) => {
    if (event.key === THEME_KEY) {
        applyTheme(storedChoice());
    }
});

/* -------------------------------------------------------------------------
 * Qualite adaptative
 * ---------------------------------------------------------------------- */

/**
 * Decide si l'appareil peut se permettre le verre flou et le reflet.
 *
 * On se fie aux capacites annoncees plutot qu'a une detection de modele :
 * peu de coeurs, peu de memoire, ou une connexion lente ou economisee. Le
 * doute profite a la fluidite — un effet manquant ne se remarque pas, un
 * defilement saccade se remarque immediatement.
 */
function prefersReducedQuality() {
    const cores = navigator.hardwareConcurrency;
    if (typeof cores === 'number' && cores > 0 && cores < 4) {
        return true;
    }

    const memory = navigator.deviceMemory;
    if (typeof memory === 'number' && memory > 0 && memory < 4) {
        return true;
    }

    const connection = navigator.connection;
    if (connection) {
        if (connection.saveData === true) {
            return true;
        }
        if (['slow-2g', '2g', '3g'].includes(connection.effectiveType)) {
            return true;
        }
    }

    return false;
}

function applyQuality() {
    root.dataset.quality = prefersReducedQuality() ? 'reduced' : 'full';
}

applyQuality();

// Le passage en mode economie de donnees peut arriver en cours de session.
navigator.connection?.addEventListener?.('change', applyQuality);

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

/* -------------------------------------------------------------------------
 * Reflet speculaire du verre
 * ---------------------------------------------------------------------- */

/**
 * Deplace la nappe de lumiere sur les surfaces en verre.
 *
 * Le pointeur est suivi au niveau du document, pas element par element : un
 * seul ecouteur, quel que soit le nombre de surfaces. Les coordonnees ne sont
 * ecrites que dans une variable CSS, donc aucun recalcul de mise en page.
 *
 * Rien ne s'installe sur un ecran tactile : sans pointeur a suivre, l'effet se
 * reduirait a une tache lumineuse figee sous le doigt, qui se lit comme un
 * defaut d'affichage. La regle CSS correspondante est bornee par la meme
 * requete media.
 */
function initSpecular() {
    if (root.dataset.quality === 'reduced') {
        return;
    }

    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        return;
    }

    const surfaces = () => document.querySelectorAll('.glass-specular');
    let pending = false;
    let lastEvent = null;

    function paint() {
        pending = false;
        if (!lastEvent) return;

        for (const surface of surfaces()) {
            const box = surface.getBoundingClientRect();

            // Marge de 120px : la lumiere s'annonce avant que le pointeur
            // n'atteigne la surface, comme un reflet qui approche.
            const near =
                lastEvent.clientX > box.left - 120 &&
                lastEvent.clientX < box.right + 120 &&
                lastEvent.clientY > box.top - 120 &&
                lastEvent.clientY < box.bottom + 120;

            if (!near) {
                surface.dataset.lit = 'false';
                continue;
            }

            const x = ((lastEvent.clientX - box.left) / box.width) * 100;
            const y = ((lastEvent.clientY - box.top) / box.height) * 100;

            surface.style.setProperty('--spec-x', `${x.toFixed(1)}%`);
            surface.style.setProperty('--spec-y', `${y.toFixed(1)}%`);
            surface.dataset.lit = 'true';
        }
    }

    window.addEventListener(
        'pointermove',
        (event) => {
            if (event.pointerType !== 'mouse') return;
            lastEvent = event;
            if (!pending) {
                pending = true;
                requestAnimationFrame(paint);
            }
        },
        { passive: true }
    );

}

/* -------------------------------------------------------------------------
 * Revelation au defilement
 * ---------------------------------------------------------------------- */

/**
 * Revele les elements marques `data-reveal` a leur entree dans l'ecran.
 *
 * `data-reveal="on"` n'est pose sur <html> qu'ici : tant que ce script n'a pas
 * tourne, la regle CSS qui masque les elements ne s'applique pas et la page
 * reste entierement lisible.
 *
 * Chaque element est oublie par l'observateur des qu'il a ete revele : rien ne
 * continue a s'evaluer pendant le reste du defilement.
 */
let revealObserver = null;

function initReveal() {
    if (reducedMotion.matches || !('IntersectionObserver' in window)) {
        return;
    }

    if (revealObserver === null) {
        revealObserver = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (!entry.isIntersecting) continue;

                    // Decalage en cascade uniquement quand l'element appartient
                    // a une vraie sequence : l'ordre de lecture porte alors une
                    // information, ce qui n'est pas le cas d'une section isolee.
                    const step = Number(entry.target.dataset.revealStep ?? 0);
                    entry.target.style.transitionDelay = `${Math.min(step, 4) * 70}ms`;
                    entry.target.classList.add('is-revealed');

                    revealObserver.unobserve(entry.target);
                }
            },
            { rootMargin: '0px 0px -12% 0px', threshold: 0.1 }
        );
    }

    observeReveals(document);
}

/**
 * Met sous surveillance les elements a reveler d'une portion du document.
 *
 * APPELEE AUSSI APRES CHAQUE MISE A JOUR LIVEWIRE, et c'est tout l'objet de
 * cette fonction. Un clic sur un filtre remplace les cartes de la liste par des
 * elements NEUFS : l'observateur installe au chargement ne les connait pas, ils
 * n'obtiennent jamais `is-revealed`, et la liste reste invisible jusqu'a ce que
 * l'utilisateur recharge la page.
 *
 * Le defaut ne se voyait qu'a l'usage : la premiere peinture est correcte, et
 * seule une seconde requete Livewire la casse.
 *
 * `data-reveal="on"` n'est pose sur <html> qu'ici : tant que ce script n'a pas
 * tourne, la regle CSS qui masque les elements ne s'applique pas et la page
 * reste entierement lisible.
 */
function observeReveals(scope) {
    if (revealObserver === null || !scope || typeof scope.querySelectorAll !== 'function') {
        return;
    }

    const targets = scope.querySelectorAll('[data-reveal]:not(.is-revealed)');
    if (targets.length === 0) return;

    root.dataset.reveal = 'on';

    for (const target of targets) {
        revealObserver.observe(target);
    }
}

/* -------------------------------------------------------------------------
 * En-tete au defilement
 * ---------------------------------------------------------------------- */

/**
 * Donne sa matiere a l'en-tete des que la page a quitte le haut.
 *
 * Le verre n'apparait qu'a ce moment : en haut de page il n'aurait rien a
 * refracter, et un `backdrop-filter` permanent coute cher pour rien.
 *
 * L'etat est calcule par un IntersectionObserver sur une sentinelle plutot
 * que par un ecouteur de defilement : aucun code ne tourne pendant le
 * defilement lui-meme.
 */
function initStickyHeader() {
    const header = document.querySelector('[data-sticky-header]');
    if (!header) return;

    const sentinel = document.createElement('div');
    sentinel.setAttribute('aria-hidden', 'true');
    sentinel.style.cssText = 'position:absolute;top:0;left:0;height:1px;width:1px;';
    document.body.prepend(sentinel);

    const observer = new IntersectionObserver(
        ([entry]) => {
            header.dataset.stuck = entry.isIntersecting ? 'false' : 'true';
        },
        { threshold: 0 }
    );

    observer.observe(sentinel);
}

/* -------------------------------------------------------------------------
 * Amorcage
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Telechargement du billet en image
 * ---------------------------------------------------------------------- */

/*
 * Expose au bouton de la page billet.
 *
 * L'`import()` reste ici, au plus haut niveau : le module de capture et sa
 * dependance ne sont demandes qu'au clic. Un visiteur qui consulte son billet
 * sans le telecharger ne paie pas un octet pour cette fonctionnalite
 * (regle 1 de ce fichier, section 72).
 */
window.Pass241 = window.Pass241 || {};

window.Pass241.downloadTicketImage = (ticket, filename) =>
    import('./ticket-image.js').then((module) => module.downloadTicketImage(ticket, filename));

function boot() {
    initSpecular();
    initReveal();
    initStickyHeader();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}

// Livewire remplace le contenu sans recharger la page : les observateurs
// doivent etre reinstalles sur le nouveau document.
document.addEventListener('livewire:navigated', boot);

/*
 * Mises a jour EN PLACE : un filtre, une pagination, un formulaire.
 *
 * `livewire:navigated` ne couvre que les navigations de page. Une simple
 * requete de composant remplace des noeuds sans declencher cet evenement — et
 * les cartes remplacees n'etaient plus jamais revelees.
 *
 * Deux crochets, volontairement :
 *
 * - `morph.added` prend chaque element ajoute, y compris ceux qu'un
 *   `wire:key` fait apparaitre seul au milieu d'une liste ;
 * - `morphed` rattrape le reste, une fois le composant entierement remplace.
 *
 * Un crochet inconnu d'une version de Livewire ne se declenche simplement
 * jamais : les enregistrer tous les deux ne coute rien et couvre les deux
 * mecaniques.
 */
document.addEventListener('livewire:init', () => {
    if (typeof window.Livewire?.hook !== 'function') {
        return;
    }

    window.Livewire.hook('morph.added', ({ el }) => {
        if (el?.hasAttribute?.('data-reveal')) {
            observeReveals(el.parentElement ?? document);
        }
    });

    window.Livewire.hook('morphed', ({ component }) => {
        observeReveals(component?.el ?? document);
    });
});
