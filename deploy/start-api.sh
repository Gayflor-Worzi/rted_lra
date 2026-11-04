#!/bin/sh
set -eu

# Apply reviewed migrations explicitly after a verified database backup.
php artisan storage:link --force
exec apache2-foreground
