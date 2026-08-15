#!/bin/bash
#
# Kill Oxigraph if it exceeds a memory ceiling. launchd's KeepAlive then
# restarts it clean, so an unbounded query becomes a few seconds of SPARQL
# downtime instead of a machine-wide memory problem.
#
# Why this is needed at all: Oxigraph 0.4.2 has no query timeout, and it does
# not stop when the client goes away. Measured on this host with
# `SELECT ?s ?p ?o WHERE { ?s ?p ?o } ORDER BY ?s`: RSS climbed past 1.2 GB and
# was still growing 50 seconds AFTER the client was killed, CPU pegged at ~87%.
# Apache's ProxyTimeout ends the caller's wait, not Oxigraph's work.
#
# macOS has no cgroups, and launchd's ResidentSetSize limit is not enforced on
# Darwin, so an external watchdog is the only lever available.
#
# The point is to protect the OTHER vhosts on this machine. The SPARQL endpoint
# is expendable; iphylo.org and bionames.org are not.

LIMIT_MB=${LIMIT_MB:-2048}
LOG=/Users/rpage/oxigraph-watchdog.log

PID=$(pgrep -n -f "oxigraph serve-read-only")
[ -z "$PID" ] && exit 0          # not running; launchd's problem, not ours

RSS_KB=$(ps -o rss= -p "$PID" 2>/dev/null | tr -d ' ')
[ -z "$RSS_KB" ] && exit 0

MB=$((RSS_KB / 1024))

if [ "$MB" -gt "$LIMIT_MB" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') killing pid $PID: RSS ${MB}MB exceeds ${LIMIT_MB}MB" >> "$LOG"
    logger -t oxigraph-watchdog "killing pid $PID: RSS ${MB}MB > ${LIMIT_MB}MB"
    kill -9 "$PID"
fi
