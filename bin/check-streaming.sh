#!/usr/bin/env bash
#
# Does Synapse actually stream on this runtime?
#
# Time-to-first-byte is the whole test. A healthy stream sends its first part
# within milliseconds and keeps the connection open for as long as the agent
# runs, so TTFB is far below the total. When the two converge, nothing streamed:
# the response was assembled in full and sent at the end, which in the dashboard
# reads as a hang followed by the entire conversation appearing at once.
#
# That is not hypothetical. Synapse shipped it for six epics — the emitter
# guarded its flush on `headers_sent()`, which is false under the stock php.ini
# (`output_buffering=4096`), so the guard answered "no" for every part of every
# run. Measured on `artisan serve`: 6.710s TTFB against 6.714s total. After the
# fix: 0.023s against 7.915s.
#
# No test tier can catch this. Feature tests and the browser driver both run
# Laravel in-process on the CLI SAPI, where flushing is deliberately off. This
# script is the only gate, so run it per runtime before tagging a release.
#
# IMPORTANT: never pipe curl into another process here. A pipe block-buffers and
# will make a healthy stream look batched — that mistake was made twice while
# diagnosing the original bug. `-w` writes the timings to stdout with the body
# discarded, which is the only form that reports the truth.
#
# Usage:
#   bin/check-streaming.sh [BASE_URL] [AGENT_SLUG]
#
#   BASE_URL     defaults to http://127.0.0.1:8000
#   AGENT_SLUG   defaults to app.agents.slow-tool-agent
#
# The agent must own a tool that takes a few seconds — `SlowTool` in the
# workbench. Every other fixture returns in microseconds, which leaves no window
# to observe and would let a fully buffered response pass.

set -euo pipefail

BASE="${1:-http://127.0.0.1:8000}"
AGENT="${2:-app.agents.slow-tool-agent}"
PATH_PREFIX="${SYNAPSE_PATH:-synapse}"

# TTFB above this means something buffered the response. Generous on purpose:
# the failure it catches is a whole-response delay, not a slow millisecond.
THRESHOLD_MS=1000

DASHBOARD="${BASE}/${PATH_PREFIX}"
ENDPOINT="${DASHBOARD}/api/chat/${AGENT}/send"
COOKIES="$(mktemp)"
trap 'rm -f "$COOKIES"' EXIT

echo "Runtime streaming check"
echo "  dashboard : ${DASHBOARD}"
echo "  agent     : ${AGENT}"
echo

# The dashboard is session-protected, so pick up the CSRF cookie the same way a
# browser would rather than asking whoever runs this to paste a token.
curl -sS -c "$COOKIES" "$DASHBOARD" -o /dev/null

TOKEN="$(
    awk '$6 == "XSRF-TOKEN" { print $7 }' "$COOKIES" \
        | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))'
)"

if [ -z "$TOKEN" ]; then
    echo "FAIL  no XSRF-TOKEN cookie from ${DASHBOARD} — is the dashboard reachable and enabled?"
    exit 1
fi

TIMINGS="$(
    curl -sSN -o /dev/null \
        -X POST "$ENDPOINT" \
        -b "$COOKIES" \
        -H "X-XSRF-TOKEN: ${TOKEN}" \
        -H 'Content-Type: application/json' \
        -H 'Accept: text/event-stream' \
        -d '{"message":"Query the analytics service with seconds=4."}' \
        -w '%{time_starttransfer} %{time_total} %{http_code}'
)"

read -r TTFB TOTAL STATUS <<< "$TIMINGS"

TTFB_MS="$(printf '%.0f' "$(echo "$TTFB * 1000" | bc -l)")"
TOTAL_MS="$(printf '%.0f' "$(echo "$TOTAL * 1000" | bc -l)")"

echo "  status    : ${STATUS}"
echo "  ttfb      : ${TTFB_MS}ms"
echo "  total     : ${TOTAL_MS}ms"
echo

if [ "$STATUS" != "200" ]; then
    echo "FAIL  the request itself failed (HTTP ${STATUS}); the timings mean nothing."
    exit 1
fi

# A run that returns instantly proves nothing either way: SlowTool should hold
# the connection open for about four seconds, so a fast total means the tool
# never ran and there was no streaming window to measure.
if [ "$TOTAL_MS" -lt 2000 ]; then
    echo "FAIL  the whole run took ${TOTAL_MS}ms — SlowTool cannot have run."
    echo "      Check that ${AGENT} owns SlowTool and the model chose to call it."
    exit 1
fi

if [ "$TTFB_MS" -gt "$THRESHOLD_MS" ]; then
    echo "FAIL  first byte took ${TTFB_MS}ms of a ${TOTAL_MS}ms run — nothing is streaming."
    echo "      The whole response was assembled before being sent. Check the SAPI"
    echo "      (Synapse::streams()), any proxy buffering, and X-Accel-Buffering."
    exit 1
fi

echo "PASS  first byte at ${TTFB_MS}ms of a ${TOTAL_MS}ms run — streaming."
