#!/bin/sh
# First start of a fresh PostgreSQL volume only: the database the PHP tests use
# (npm run test:php:matrix), owned by the same user as the site's database.
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -c "CREATE DATABASE kestello_test OWNER \"$POSTGRES_USER\";"
