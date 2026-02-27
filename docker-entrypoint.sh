#!/bin/bash
set -e

# Pass env vars to cron (cron has no access to Docker env vars by default)
printenv | grep -vE '^(HOME|PATH|SHELL|USER|LOGNAME|_)=' > /etc/environment

# Start cron in background
cron

# Rebuild cache for new code (volume has stale cache from previous deploy)
echo "Clearing and warming up Symfony cache..."
php /var/www/html/bin/console cache:clear --env=prod --no-warmup || true
php /var/www/html/bin/console cache:warmup --env=prod || true

# Ensure sessions dir exists (persistent across deploys)
mkdir -p /var/www/html/var/sessions
chown www-data:www-data /var/www/html/var/sessions

# Run database migrations
echo "Running database migrations..."
php /var/www/html/bin/console app:db:migrate || true

# Refresh Saxo token first (may have expired during deploy)
echo "Refreshing Saxo token..."
php /var/www/html/bin/console app:saxo:refresh || true

# Pre-fetch IB data on startup (slow API, cache for 1h)
echo "Pre-fetching IB data..."
php /var/www/html/bin/console app:ib:fetch || true

# Import IB transactions into database
echo "Importing IB transactions..."
php /var/www/html/bin/console app:transactions:import || true

# Warmup momentum cache on startup
echo "Warming up momentum cache..."
php /var/www/html/bin/console app:momentum:warmup || true

# Warmup full dashboard cache (uses IB + Saxo + momentum + macro APIs)
echo "Warming up dashboard cache..."
php /var/www/html/bin/console app:dashboard:warmup || true

echo "Startup warmup complete, starting Apache..."

# Start Apache in foreground
exec apache2-foreground
