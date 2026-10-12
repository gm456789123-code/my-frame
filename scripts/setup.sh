#!/usr/bin/env bash
# One-command local setup. Safe to re-run (idempotent): skips steps that are
# already done instead of failing or duplicating them.
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    cp .env.example .env
    echo "Created .env from .env.example"
fi

echo "Starting Docker services..."
docker compose up -d --build

echo "Waiting for MySQL..."
tries=0
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -proot --silent >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "MySQL did not become ready in time. Check: docker compose logs mysql" >&2
        exit 1
    fi
    sleep 2
done

echo "Installing PHP dependencies..."
docker compose exec app composer install --no-interaction

if docker compose --profile tools run --rm wpcli core is-installed >/dev/null 2>&1; then
    echo "WordPress already installed, skipping."
else
    echo "Installing WordPress..."
    docker compose --profile tools run --rm wpcli core install \
        --url=http://localhost:8080 --title="Sovereign Engine" \
        --admin_user=admin --admin_password=admin --admin_email=admin@example.com
fi

echo "Running migrations..."
docker compose exec app php bin/console db:migrate

cat <<'EOF'

Done.
  Site:     http://localhost:8080
  wp-admin: http://localhost:8080/wp-admin  (admin / admin)

Verify:      docker compose exec app composer check
Try it:      docker compose exec app php bin/console page:create "Hello" "<p>World</p>"
EOF
