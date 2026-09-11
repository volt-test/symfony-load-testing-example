#!/bin/sh
set -e

# Same bootstrap as the FrankenPHP image: local-only JWT keypair, optional migrations.
if [ ! -f config/jwt/private.pem ] && [ -z "$JWT_SECRET_KEY" ]; then
	php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
fi

if [ "$MIGRATE_ON_START" = "1" ]; then
	until php bin/console dbal:run-sql -q "SELECT 1" 2>/dev/null; do
		echo "Waiting for database..."
		sleep 1
	done
	php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

if [ "$1" = 'serve' ]; then
	php-fpm -D
	exec nginx -g 'daemon off;'
fi

exec "$@"
