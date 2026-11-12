#!/bin/sh
# The PHP tests against MySQL, PostgreSQL and Redis as well as SQLite, like CI.
# Starts the optional pgsql and redis services, then runs the tests in the app container
# with connection settings docker-compose.yml builds from .env (KESTELLO_MATRIX_*):
# once with every database (the database tests loop over them), then the whole suite
# again on each database in turn (KESTELLO_TEST_DATABASE, see tests/Support/TestDatabase.php).
# Usage: sh tools/test-matrix.sh [test path]
set -eu
docker compose --profile pgsql --profile redis up -d --wait db pgsql redis app >/dev/null
exec docker compose exec -T app sh -c '
set -e
KESTELLO_TEST_DATABASES="$KESTELLO_MATRIX_DATABASES" KESTELLO_TEST_REDIS="$KESTELLO_MATRIX_REDIS" php -d apc.enable_cli=1 bin/cms test "$@"
php -r "foreach (json_decode(getenv(\"KESTELLO_MATRIX_DATABASES\"), true) ?: [] as \$c) echo json_encode(\$c), PHP_EOL;" | while read -r config; do
    echo "On $(echo "$config" | php -r "echo json_decode(stream_get_contents(STDIN), true)[\"driver\"];"):"
    KESTELLO_TEST_DATABASES="" KESTELLO_TEST_DATABASE="$config" php -d apc.enable_cli=1 bin/cms test "$@"
done
' sh "$@"
