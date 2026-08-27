#!/bin/sh
set -e

# Le code est monte depuis Windows : les fichiers appartiennent a root, alors
# que les workers PHP-FPM tournent en www-data. Sans cet ajustement, Blade ne
# peut pas ecrire ses vues compilees et toute page pas encore compilee renvoie
# une erreur 500 (tempnam(): file created in the system's temporary directory).
for dir in storage bootstrap/cache; do
    if [ -d "/var/www/html/$dir" ]; then
        chmod -R a+rwX "/var/www/html/$dir" 2>/dev/null || true
    fi
done

# storage/framework est un volume Docker : il appartient a root et demarre
# vide. Les sous-dossiers attendus par Laravel doivent exister avant le premier
# rendu, sinon Blade n'a nulle part ou ecrire ses vues compilees.
for dir in cache/data sessions testing testing/views views; do
    mkdir -p "/var/www/html/storage/framework/$dir"
done

chmod -R a+rwX /var/www/html/storage/framework 2>/dev/null || true

# Et la PROPRIETE, pas seulement les droits.
#
# `chmod a+rwX` suffit pour ecrire, mais pas pour `touch()` : fixer la date de
# modification d'un fichier qu'on ne possede pas est refuse par le noyau, quels
# que soient les droits. Or Blade fait exactement cela apres avoir compile une
# vue — d'ou un « Utime failed: Operation not permitted » en pleine page.
#
# Le cas se produit des qu'une vue a ete compilee par root : `php artisan test`
# ou `php artisan view:cache` lances depuis `docker compose exec` en sont les
# sources habituelles. Les workers, eux, tournent en www-data.
chown -R www-data:www-data /var/www/html/storage/framework 2>/dev/null || true

exec "$@"
