#!/bin/bash
set -e

# Pass env vars to cron (cron has no access to Docker env vars by default)
printenv | grep -vE '^(HOME|PATH|SHELL|USER|LOGNAME|_)=' > /etc/environment

# Start cron in background
cron

# Warmup momentum cache on startup
php /var/www/html/bin/console app:momentum:warmup || true

# Start Apache in foreground
exec apache2-foreground
