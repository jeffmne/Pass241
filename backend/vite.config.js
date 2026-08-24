import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Section 70.2 : Manrope pour les titres et les montants mis en
            // avant, Inter pour l'interface et les tableaux. Deux familles, pas
            // une de plus. Les fichiers sont telecharges a la construction et
            // servis depuis notre domaine : pas d'appel a un CDN tiers, ce qui
            // compte sur les connexions mobiles du marche vise.
            fonts: [
                bunny('Manrope', { weights: [600, 700, 800] }),
                bunny('Inter', { weights: [400, 500, 600] }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
