@props([
    // `full` : le bouton porte son libelle. Pour un pied de colonne laterale
    //          ou un ecran de reglages, ou il y a la place.
    // `compact` : l'icone seule. Pour une barre d'en-tete etroite, ou chaque
    //             pixel est dispute par la marque et le profil.
    'variant' => 'full',
])

{{--
    Bascule clair / sombre (regle 18).

    UN SEUL BOUTON, qui alterne entre les deux themes.

    Le produit demarre sur la preference du systeme — un telephone en mode nuit
    ouvre Pass241 en sombre, sans que personne ait rien regle. Le bouton n'entre
    en jeu qu'a partir du moment ou l'on veut autre chose que ce que dit
    l'appareil ; a partir de cette premiere pression, le choix est retenu et ne
    bouge plus.

    L'ICONE MONTRE LA DESTINATION, pas l'etat courant : une lune en mode clair,
    un soleil en mode sombre. C'est la convention etablie, et c'est la seule qui
    reponde a la question qu'on se pose devant un bouton — « qu'est-ce qui se
    passe si j'appuie ». Afficher l'etat actuel obligerait a deviner.

    `aria-pressed` porte l'etat pour les lecteurs d'ecran, et le libelle change
    avec lui : « Passer en mode sombre » puis « Passer en mode clair ». Une
    icone seule, meme parlante, ne dit rien a qui ne voit pas.

    Aucun `x-cloak` n'est necessaire : le theme est deja pose sur <html> par
    `x-theme-script` avant la premiere peinture, donc la bonne icone est
    affichee des le premier rendu, avant meme qu'Alpine ne prenne la main.
--}}
<button type="button"
        x-data="{
            dark: document.documentElement.dataset.theme === 'dark',
            toggle() {
                this.dark = ! this.dark;
                window.Pass241?.theme?.set(this.dark ? 'dark' : 'light');
            },
            init() {
                // Un autre onglet, ou le systeme qui bascule au coucher du
                // soleil tant qu'aucun choix explicite n'a ete fait.
                document.addEventListener('pass241:theme', (event) => {
                    this.dark = event.detail.theme === 'dark';
                });
            },
        }"
        @click="toggle()"
        :aria-pressed="dark ? 'true' : 'false'"
        aria-pressed="false"
        :aria-label="dark ? 'Passer en mode clair' : 'Passer en mode sombre'"
        {{ $attributes->class([
            'btn',
            'btn-secondary w-full' => $variant === 'full',
            'btn-icon' => $variant === 'compact',
        ]) }}>

    {{-- Les deux icones sont posees l'une SUR l'autre et se croisent en
         opacite. Les echanger dans le DOM ferait sauter la largeur du bouton
         d'un pixel a chaque pression, et avec elle toute la barre. --}}
    <span class="relative flex size-5 shrink-0 items-center justify-center">
        <x-lucide-moon class="absolute size-5 transition-opacity duration-150"
                       ::class="dark ? 'opacity-0' : 'opacity-100'"
                       stroke-width="1.75" aria-hidden="true" />
        <x-lucide-sun class="absolute size-5 opacity-0 transition-opacity duration-150"
                      ::class="dark ? 'opacity-100' : 'opacity-0'"
                      stroke-width="1.75" aria-hidden="true" />
    </span>

    @if ($variant === 'full')
        <span x-text="dark ? 'Mode clair' : 'Mode sombre'">Mode sombre</span>
    @endif
</button>
