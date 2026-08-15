# Deploying the public SPARQL endpoint

Read-only Oxigraph behind Caddy. Oxigraph listens only on loopback; Caddy holds
the certificate and is the sole public listener.

Sized for the current dataset: ~112.3M triples from `taxa.nt`, `references.nt`,
`gbif-col.nt` and `sources.nt` (~13.2 GB of N-Triples, ~24 GB as a RocksDB
store, projected at the measured 227 bytes/triple). Budget ~60 GB of disk for
two store slots plus the source files.

## Install

```sh
# 1. User and directories
useradd --system --home-dir /var/lib/oxigraph --shell /usr/sbin/nologin oxigraph
install -d -o oxigraph -g oxigraph /var/lib/oxigraph
install -d -o caddy -g caddy /var/log/caddy

# 2. Oxigraph binary (match the version you built the store with)
curl -L -o /usr/local/bin/oxigraph \
  https://github.com/oxigraph/oxigraph/releases/download/v0.4.2/oxigraph_v0.4.2_x86_64-unknown-linux-gnu
chmod +x /usr/local/bin/oxigraph

# 3. Service
cp oxigraph.service /etc/systemd/system/
systemctl daemon-reload

# 4. Load data (see below), then:
systemctl enable --now oxigraph

# 5. Caddy — edit the hostname in the Caddyfile first
cp Caddyfile /etc/caddy/Caddyfile
systemctl reload caddy
```

## Getting the data onto the box

Do not copy the macOS `oxigraph/` directory across. RocksDB store portability
across platform and Oxigraph version is not guaranteed, and a subtly broken
19 GB store is a bad thing to debug remotely. Ship the N-Triples and reload:

```sh
zstd -T0 taxa.nt references.nt gbif-col.nt sources.nt
rsync -avP *.nt.zst root@your-host:/srv/col-rdf/
# on the server
unzstd /srv/col-rdf/*.nt.zst
./oxigraph-load.sh /srv/col-rdf/*.nt
```

`oxigraph-load.sh` alternates between `/var/lib/oxigraph/store-a` and
`store-b`, loading into whichever is idle and swapping the `current` symlink at
the end. This is what keeps a reload down to a few seconds rather than the
length of a bulk load: `oxigraph load` is a writer, and Oxigraph documents
opening a store read-only while another process writes it as undefined
behaviour, so the live store cannot be loaded into. The previous slot stays on
disk as a rollback; the script prints the command.

The script also runs `oxigraph optimize` on the freshly loaded slot. Oxigraph's
own help calls that out as worth doing precisely for "a read-only SPARQL
endpoint under heavy load", which is this case exactly.

Concurrent *readers* of one store are explicitly supported. If a single process
turns out to be the bottleneck, you can run several `serve-read-only` instances
on different ports against the same `current` directory and let Caddy load
balance across them — that is a cheaper answer to concurrency than moving off
Oxigraph.

## Things that will bite you

**Duplicate CORS headers.** Caddy sets `Access-Control-Allow-Origin`. Do not
also pass `--cors` to Oxigraph — browsers reject a response carrying the header
twice, and the failure looks like a generic CORS error with no clue as to why.

**Query timeouts are not real timeouts.** Oxigraph 0.4.2 has no query time
limit; its only relevant flags are `--bind`, `--cors` and
`--union-default-graph`. Caddy's `read_timeout` ends the *client's* wait, but
Oxigraph keeps evaluating until it notices the closed socket. A pathological
query can therefore still burn a core after the caller has gone. If that turns
out to matter in practice, the honest fix is a second Oxigraph process for
untrusted traffic with a hard `MemoryMax`/`CPUQuota`, not a bigger proxy
timeout.

**`rate_limit` is not in stock Caddy.** It needs
`xcaddy build --with github.com/mholt/caddy-ratelimit`. If you deploy the
distro package, delete that block or Caddy will refuse to start on an unknown
directive.

**`MemoryMax=48G` in the unit assumes a ~64 GB box.** Adjust it. Oxigraph is
disk-backed and the page cache is what makes it quick, so leave room rather
than handing the process everything.

**If the service fails to start under the hardening block,** comment out
`SystemCallFilter` first and `ProtectSystem` second — those are the two most
likely to disagree with a given RocksDB build. `journalctl -u oxigraph -n 50`
will name the offending call.

## Checks

```sh
# through Caddy
curl -sG https://sparql.example.org/query \
  --data-urlencode 'query=SELECT (COUNT(*) AS ?n) WHERE { ?s ?p ?o }' \
  -H 'Accept: application/sparql-results+json'

# writes must be refused
curl -si -X POST https://sparql.example.org/update \
  --data-urlencode 'update=DROP ALL' | head -1   # expect 403
```
