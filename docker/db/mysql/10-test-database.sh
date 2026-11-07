#!/bin/bash
# First start of a fresh MySQL volume only: the database the PHP tests use
# (npm run test:php:matrix), owned by the same user as the site's database.
set -eu
mysql --protocol=socket -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS kestello_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON kestello_test.* TO '${MYSQL_USER}'@'%';
SQL
