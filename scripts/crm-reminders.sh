#!/usr/bin/env bash
# A dedicated reminder worker; intentionally does not calculate KPI snapshots.
set -u
child_pid=""
cleanup() {
    trap - EXIT TERM INT
    if [ -n "$child_pid" ]; then kill "$child_pid" 2>/dev/null || true; fi
    wait 2>/dev/null || true
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
while true; do
    php artisan tasks:check-overdue --no-interaction &
    child_pid=$!
    if ! wait "$child_pid"; then
        echo "CRM reminders failed; retrying in five minutes." >&2
    fi
    sleep 300 &
    child_pid=$!
    wait "$child_pid"
done
