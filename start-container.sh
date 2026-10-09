#!/usr/bin/env bash
set -e

# Keep migrations in Railway's pre-deploy step; never reseed demo accounts here.
if [ -f artisan ]; then
    if [ "${RAILPACK_SKIP_MIGRATIONS:-false}" != "true" ]; then
        php artisan migrate --force --no-interaction
    fi
    if [ ! -e public/storage ] && [ ! -L public/storage ]; then
        php artisan storage:link --no-interaction
    fi
    php artisan optimize:clear --no-interaction
    php artisan optimize --no-interaction
fi

reminder_pid=""
server_pid=""
cleanup() {
    trap - EXIT TERM INT
    if [ -n "$reminder_pid" ]; then kill "$reminder_pid" 2>/dev/null || true; fi
    if [ -n "$server_pid" ]; then kill "$server_pid" 2>/dev/null || true; fi
    wait 2>/dev/null || true
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT

if [ "${CRM_REMINDERS_ENABLED:-false}" = "true" ]; then
    echo "CRM overdue reminders enabled: checking every five minutes."
    bash scripts/crm-reminders.sh &
    reminder_pid=$!
else
    echo "CRM reminders disabled. Set CRM_REMINDERS_ENABLED=true to enable."
fi

docker-php-entrypoint --config /Caddyfile --adapter caddyfile &
server_pid=$!
wait "$server_pid"
