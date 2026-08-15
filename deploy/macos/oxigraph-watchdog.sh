#!/bin/bash
#
# Kill Oxigraph when a query has clearly run away. launchd's KeepAlive restarts
# it clean, so the failure becomes a few seconds of SPARQL downtime rather than
# a machine-wide problem for the other vhosts on this host.
#
# ── Why CPU duration, not RSS ────────────────────────────────────────────────
#
# The first version of this watched RSS and would never have fired. Measured on
# this host with `SELECT ?s ?p ?o WHERE { ?s ?p ?o } ORDER BY ?s`:
#
#   21:06:14   48 MB
#   21:06:24 5546 MB   <- spike
#   21:06:34 4756 MB
#   21:06:44 1608 MB
#   21:06:54  895 MB
#   ...then oscillating 1.0-1.6 GB for minutes
#
# RSS on a RocksDB process counts mmap'd file pages, which the kernel reclaims
# under pressure. So it spikes and falls back on its own and is a poor signal:
# a 30s sampler misses the spike entirely, and a threshold low enough to catch
# it would kill legitimate heavy queries.
#
# What actually persists is CPU. That query held ~85% CPU indefinitely, and kept
# going after the client was killed — Oxigraph 0.4.2 has no query timeout and
# does not notice the caller leaving. Apache's ProxyTimeout ends the caller's
# wait, not the work.
#
# So: sustained high CPU across consecutive checks is the signal. A legitimate
# full scan takes ~23s, i.e. one sample at most. Three consecutive samples means
# ~90s of continuous burn, which no honest query on this dataset does.
#
# macOS has no cgroups and launchd's ResidentSetSize is unenforced on Darwin, so
# an external watchdog is the only lever available.

CPU_PCT=${CPU_PCT:-50}        # busy threshold
STRIKES=${STRIKES:-3}         # consecutive busy checks before killing
RSS_CEILING_MB=${RSS_CEILING_MB:-6144}   # backstop for a genuine runaway
STATE=/Users/rpage/.oxigraph-watchdog-state
LOG=/Users/rpage/oxigraph-watchdog.log

PID=$(pgrep -n -f "oxigraph serve-read-only")
if [ -z "$PID" ]; then
    echo 0 > "$STATE"          # not running; launchd's problem, not ours
    exit 0
fi

read -r RSS_KB CPU <<< "$(ps -o rss=,%cpu= -p "$PID" 2>/dev/null)"
[ -z "$RSS_KB" ] && exit 0
MB=$((RSS_KB / 1024))
CPU_INT=${CPU%%.*}
CPU_INT=${CPU_INT:-0}

COUNT=$(cat "$STATE" 2>/dev/null || echo 0)
case "$COUNT" in ''|*[!0-9]*) COUNT=0 ;; esac

if [ "$CPU_INT" -ge "$CPU_PCT" ]; then
    COUNT=$((COUNT + 1))
else
    COUNT=0
fi
echo "$COUNT" > "$STATE"

kill_it() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') killing pid $PID — $1 (RSS ${MB}MB, CPU ${CPU}%)" >> "$LOG"
    logger -t oxigraph-watchdog "killing pid $PID: $1"
    kill -9 "$PID"
    echo 0 > "$STATE"
}

if [ "$MB" -gt "$RSS_CEILING_MB" ]; then
    kill_it "RSS ${MB}MB over ${RSS_CEILING_MB}MB"
elif [ "$COUNT" -ge "$STRIKES" ]; then
    kill_it "CPU >= ${CPU_PCT}% for $COUNT consecutive checks"
fi
