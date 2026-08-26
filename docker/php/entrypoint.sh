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
for dir in cache/data sessions testing views; do
    mkdir -p "/var/www/html/storage/framework/$dir"
done

chmod -R a+rwX /var/www/html/storage/framework 2>/dev/null || true

exec "$@"
