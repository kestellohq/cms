#!/bin/sh
# The PHP tests against MySQL, PostgreSQL and Redis as well as SQLite, like CI.
# Starts the optional pgsql and redis services, then runs the tests in the app container
# with connection settings docker-compose.yml builds from .env (KESTELLO_MATRIX_*).
# Usage: sh tools/test-matrix.sh [test path]
set -eu
docker compose --profile pgsql --profile redis up -d --wait db pgsql redis app >/dev/null
exec docker compose exec -T app sh -c 'KESTELLO_TEST_DATABASES="$KESTELLO_MATRIX_DATABASES" KESTELLO_TEST_REDIS="$KESTELLO_MATRIX_REDIS" exec php -d apc.enable_cli=1 bin/cms test "$@"' sh "$@"
