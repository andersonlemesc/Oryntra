#!/usr/bin/env bash
# Watches the async agent-runtime ceiling during a load test.
#
# Prints, once per second:
#   - agent_runs counts by status (load-test rows only: conversation_id >= 900000)
#   - depth of the Redis `agent` queue (pending jobs waiting for a worker)
#
# Usage: bash scripts/loadtest-watch.sh
# Stop with Ctrl-C.
set -euo pipefail

cd "$(dirname "$0")/.."

SQL="select status, count(*) from agent_runs where conversation_id >= 900000 group by status order by status;"

while true; do
  ts=$(date +%H:%M:%S)

  statuses=$(docker compose exec -T postgres \
    psql -U "${DB_USERNAME:-oryntra}" -d "${DB_DATABASE:-oryntra}" -At -F' ' -c "$SQL" 2>/dev/null \
    | tr '\n' ' ')

  qdepth=$(docker compose exec -T redis redis-cli LLEN queues:agent 2>/dev/null | tr -d '\r')

  printf '%s | queue:agent=%s | %s\n' "$ts" "${qdepth:-?}" "${statuses:-(none)}"
  sleep 1
done
