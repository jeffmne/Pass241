import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Section 70.2 : UNE SEULE famille, Plus Jakarta Sans, de 400 a 800.
            //
            // Elle remplace le couple Manrope + Inter. Le couple tenait par un
            // contraste de dessin entre titres et interface ; Plus Jakarta Sans
            // porte ce contraste dans sa propre graisse — 800 pour un montant,
            // 400 pour une ligne de tableau, et l'ecart se lit deja. Une
            // famille de moins, c'est aussi une requete et un fichier de moins
            // sur une connexion mobile gabonaise (section 72).
            //
            // Les fichiers sont telecharges A LA CONSTRUCTION et servis depuis
            // notre domaine : aucun appel a Google Fonts ni a un autre CDN au
            // moment du rendu, contrairement a la maquette de reference.
            fonts: [bunny('Plus Jakarta Sans', { weights: [400, 500, 600, 700, 800] })],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
