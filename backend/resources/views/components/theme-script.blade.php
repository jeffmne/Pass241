{{--
    Application du theme AVANT le premier rendu.

    Ce script est volontairement en ligne et synchrone, tout en haut du <head>.
    C'est le seul endroit du produit ou l'on accepte du JavaScript bloquant, et
    la raison est precise : `resources/js/app.js` est charge en differe par
    Vite. Si le theme y etait applique, la page s'afficherait d'abord en clair,
    puis basculerait en sombre sous les yeux de l'utilisateur. Un flash blanc
    d'un dixieme de seconde en pleine nuit se remarque, et se retient.

    Il tient en quelques centaines d'octets et n'attend aucune ressource : son
    cout est celui de son analyse, invisible a cote de ce qu'il evite.

    Le `try` n'est pas de la precaution decorative : en navigation privee,
    `localStorage` LEVE une exception a la lecture sur certains navigateurs.
    Sans lui, l'erreur interromprait le script et la page resterait sans theme.
--}}
<script>
    (function () {
        try {
            var stored = localStorage.getItem('pass241:theme');
            var choice = stored === 'light' || stored === 'dark' ? stored : 'system';
            var theme = choice === 'system'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : choice;

            document.documentElement.dataset.theme = theme;
            document.documentElement.dataset.themeChoice = choice;
        } catch (e) {
            document.documentElement.dataset.theme = 'light';
            document.documentElement.dataset.themeChoice = 'system';
        }
    })();
</script>
