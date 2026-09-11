#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	# Local convenience only: generate a JWT keypair if none was provided.
	# In production JWT_SECRET_KEY / JWT_PUBLIC_KEY hold the PEM content (Fly
	# secrets) so every machine signs the same way and nothing is generated.
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
fi

exec docker-php-entrypoint "$@"
