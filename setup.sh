#!/bin/sh
composer install --optimize-autoloader;
php app/console cache:clear --env=prod --no-debug;
php app/console assets:install web --symlink;
php app/console assetic:dump;
php app/console assetic:dump --env=prod --no-debug;
php app/console doctrine:schema:validate
