#!/bin/sh
set -eu

php artisan migrate --force --no-interaction
php artisan storage:link --force
exec apache2-foreground
