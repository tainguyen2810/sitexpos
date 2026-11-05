#!/usr/bin/env bash
# Phase 3 E2E: a REAL queue worker is killed with SIGKILL while a workflow is
# inside a (fake, offline) paid AI call; a new worker + the recovery command
# must finish or safely fail every run — nothing is lost, nothing is billed twice.
#
# DEV ONLY. Wipes the database named by E2E_DB (default sitexpost_e2e).
# Needs: MySQL/MariaDB + Redis running, backend/.env configured, fake provider.
# Usage: scripts/e2e/phase3-worker-restart.sh
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT/backend"

export APP_ENV=local
export DB_DATABASE="${E2E_DB:-sitexpost_e2e}"
export QUEUE_CONNECTION=redis
export REDIS_QUEUE=e2e-default
export SITEX_AI_FAKE_PROVIDER=true
export OPENAI_API_KEY= GEMINI_API_KEY= ANTHROPIC_API_KEY=
# Short timings so the test takes ~2 minutes instead of 6+.
export SITEX_WORKFLOW_LEASE_SECONDS=60
export REDIS_QUEUE_RETRY_AFTER=55
export SITEX_AI_RESERVATION_TTL_MINUTES=1
export CACHE_STORE=redis

H="php $ROOT/scripts/e2e/phase3-helper.php"
pass() { echo "✓ $*"; }
fail() { echo "✗ $*"; exit 1; }
field() { php -r '$d=json_decode($argv[1],true); $v=$d; foreach(explode(".",$argv[2]) as $k){$v=$v[$k]??null;} echo is_array($v)?json_encode($v):var_export($v,true);' "$1" "$2"; }
worker() { php artisan queue:work redis --queue=writing --sleep=1 --tries=3 --timeout=50 >>"${TMPDIR:-/tmp}/sitex-e2e-worker.log" 2>&1 & echo $!; }

php artisan migrate:fresh --seed --force >/dev/null
redis-cli DEL "queues:writing" "queues:writing:reserved" "queues:writing:delayed" >/dev/null || true
pass "fresh database ${DB_DATABASE}, AI registry seeded"

# --- Scenario A: worker killed during a paid call, paid retry NOT allowed ---
WS=$($H setup 0)
RUN_A=$($H start "$WS" "[sleep:15] e2e crash during call")
RUN_Q=$($H start "$WS" "e2e queued behind")
W1=$(worker)
sleep 5
S=$($H status "$WS" "$RUN_A")
[ "$(field "$S" status)" = "'running'" ] || fail "run A should be running, got $S"
kill -9 "$W1"; pass "worker $W1 killed (SIGKILL) while run A was in the paid step"

W2=$(worker)
echo "  waiting for the lease (60 s) to expire…"
sleep 65
php artisan sitex:workflows:recover
sleep 8
S=$($H status "$WS" "$RUN_A")
[ "$(field "$S" status)" = "'failed'" ] || fail "run A should be failed, got $S"
[ "$(field "$S" error)" = "'interrupted_paid_call'" ] || fail "run A error: $S"
[ "$(field "$S" ai_requests)" = "0" ] || fail "run A must not have called the provider again: $S"
pass "run A → failed (interrupted_paid_call), no second paid call, dead letter: $(field "$S" dead_letters)"

S=$($H status "$WS" "$RUN_Q")
[ "$(field "$S" status)" = "'succeeded'" ] || fail "queued run should have completed on the new worker: $S"
pass "run queued behind the crash completed on the new worker (nothing lost)"

php artisan sitex:ai:expire-reservations
S=$($H status "$WS" "$RUN_A")
pass "orphan reservation of the killed call settled conservatively: $(field "$S" reservations)"

# --- Scenario B: same crash, route allows paid retry → completes ---
WS2=$($H setup 1)
RUN_B=$($H start "$WS2" "[sleep:15] e2e crash with paid retry")
sleep 5
kill -9 "$W2"; pass "worker $W2 killed during run B"
W3=$(worker)
sleep 65
php artisan sitex:workflows:recover
for i in $(seq 1 30); do
  S=$($H status "$WS2" "$RUN_B")
  [ "$(field "$S" status)" = "'succeeded'" ] && break
  sleep 2
done
[ "$(field "$S" status)" = "'succeeded'" ] || fail "run B should succeed after recovery: $S"
pass "run B recovered and succeeded (attempts=$(field "$S" attempts))"

kill "$W3" 2>/dev/null || true
echo "All Phase 3 worker-restart checks passed."
