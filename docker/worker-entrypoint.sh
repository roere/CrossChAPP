#!/bin/sh
set -eu

mkdir -p /var/www/data

exec php /var/www/html/bin/refresh-worker.php
