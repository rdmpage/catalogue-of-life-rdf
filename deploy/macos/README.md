# Mac Mini proof of concept

Read-only Oxigraph on the Mac Mini, published through the Apache that already
serves iphylo.org.

```
visitor ──HTTPS──> Apache (iphylo.org) ──proxy──> oxigraph 127.0.0.1:7878
                        │
                        └── serves ~/Sites/iphylo/col/index.html
```

Use `apache-col-sparql.conf`. Apache already terminates TLS and is already
externally reachable, so **neither Caddy nor a Cloudflare tunnel is needed
here** — they would only duplicate what Apache does.

**The git checkout is the web directory.** Clone straight into place:

```sh
git clone https://github.com/rdmpage/catalogue-of-life-rdf.git ~/Sites/iphylo/col
```

`index.html` sits at the repo root, so `https://iphylo.org/col/` just works and
a `git pull` deploys. That convenience has a cost: a checkout in a web root
contains `.git/`, the PHP generators, the 5 GB ColDP dump and ~13 GB of `.nt`.
The Apache conf therefore **denies everything and allows only `index.html`** —
an allow-list, because a deny-list leaves every new file public until someone
remembers to block it.

The sharpest edge is the PHP: if mod_php is active, fetching
`https://iphylo.org/col/taxa.php` would *execute* it and start emitting millions
of triples. The conf blocks `.php` twice over — `SetHandler none` plus a denial.

The Oxigraph store lives at `~/oxigraph-col`, outside the web root by design.
Keeping a 24 GB RocksDB directory out of the served tree beats trusting a rule
to hide it.

Oxigraph still binds to loopback only. Apache is the sole public listener, and
it is what blocks the write paths and sets CORS.

The endpoint lands at `https://iphylo.org/col/query`, with the landing page at
`https://iphylo.org/col/`. The page derives its endpoint from its own URL, so
moving the prefix needs no edit to the HTML.

### If you would rather not use Apache

`Caddyfile` in this directory is a standalone alternative (its own TLS, its own
listener). A Cloudflare tunnel would suit a host that is *not* already
externally reachable. Neither applies to the Mini as currently set up.
`deploy/` at the repo root is the Linux/systemd equivalent for a Hetzner box.

### What Apache does not give you

Rate limiting. `mod_ratelimit` throttles bandwidth, not request rate; that needs
`mod_qos` or `mod_evasive`, neither of which ships with Apache. For a proof of
concept shown to a few people that is a defensible gap — just a known one.

## Will it actually run on 16 GB?

Yes, with caveats worth knowing before you demo it.

The store is ~19–20 GB fully loaded, so it does not fit in 16 GB of RAM.
Oxigraph is disk-backed (RocksDB) and works fine that way — the page cache
holds the hot parts and the rest comes off the SSD, which on an M1 is quick.

For calibration: `SELECT (COUNT(*)) WHERE { ?s ?p ?o }` over 75.7M triples took
**18 seconds** on an M1 Pro with 32 GB. That is a full index scan, i.e. close
to the worst case, and the Mini with half the RAM will be slower. Ordinary
lookups with a bound subject or predicate return in milliseconds. The demo will
feel fine; a careless query will not.

Two real limits, stated plainly:

- **No memory ceiling is available.** macOS has no cgroups, so there is no
  equivalent of systemd's `MemoryMax`. A query returning tens of millions of
  rows can push the Mini into heavy swap. Nothing in this config prevents that.
- **No query timeout exists in Oxigraph 0.4.2.** Apache's `ProxyTimeout 90`
  ends the *client's* wait, but Oxigraph keeps evaluating until it notices the
  closed socket. A runaway query keeps burning CPU after the visitor has gone.
  This was observed for real: 33-38% CPU with RSS still climbing three minutes
  after the caller disconnected.

For a handful of known people clicking around, both are acceptable — see
"Who can reach it" below.

## Disk

The Mini has **~500 GB free**, so disk is no longer a constraint. For reference:

| | |
|---|---|
| Real triples across all four files | 102,328,986 |
| Actually loaded (0.02% dedup) | **102,308,859** |
| Store on disk, after `optimize` | **18 GB** |
| Load time / optimize time | 2m57s / 1m32s |
| Two A/B slots plus the `.nt` files | ~49 GB |

Note the four files total 112,290,863 *lines* but only 102,328,986 triples —
every record block ends with a blank line. Do not size from `wc -l`.

With room for two stores, the Mini uses the same A/B swap as the Linux box:
`load-mini.sh` builds into whichever of `oxigraph/store-a` and `oxigraph/store-b`
is idle, verifies it, then swaps the `oxigraph/current` symlink the daemon
points at. **The endpoint stays up for the entire load** — downtime is one
service restart. The previous slot stays on disk as a rollback.

This matters more than it sounds. You will reload as the RDF mapping changes,
and the alternative is the endpoint being down for the length of every bulk
load.

## Building the store

Compress for the transfer — ~0.8 GB rather than 13.2 GB is still worth it even
with disk to spare:

```sh
zstd -T0 taxa.nt references.nt gbif-col.nt sources.nt
rsync -avP *.nt.zst mini.local:/Users/rpage/col-rdf/
```

Then on the Mini, decompress and load. No need to stop the service:

```sh
unzstd /Users/rpage/col-rdf/*.nt.zst
./load-mini.sh /Users/rpage/col-rdf
```

The script loads all four files in parallel (`oxigraph load -f` parallelises
across files), runs `optimize` — which Oxigraph's own help recommends
specifically for "a read-only SPARQL endpoint under heavy load" — then refuses
to swap if the new slot holds fewer than 90M triples, so a truncated load
cannot go live. **Raise that threshold** — `taxa.nt` alone is now 96.3M.

`--lenient` skips validation for a useful speedup on files this repo generated
itself.

Do not copy the existing macOS `oxigraph/` store directory across. RocksDB
portability across machines and versions is not guaranteed, and a subtly broken
19 GB store is a bad thing to debug remotely.

Note the layout change: the store now lives at `oxigraph/store-a` and
`oxigraph/store-b` with `oxigraph/current` symlinked to one of them, rather than
directly at `oxigraph/`. The dev store on the laptop is unaffected — it is a
separate machine — but the plist expects the new layout.

## Setup

```sh
# 0. Clone into place; the checkout is the web directory
git clone https://github.com/rdmpage/catalogue-of-life-rdf.git ~/Sites/iphylo/col

# 1. Oxigraph as a daemon (edit UserName in the plist first)
sudo cp org.catalogueoflife.oxigraph.plist /Library/LaunchDaemons/
sudo chown root:wheel /Library/LaunchDaemons/org.catalogueoflife.oxigraph.plist
sudo launchctl bootstrap system /Library/LaunchDaemons/org.catalogueoflife.oxigraph.plist

# 2. Apache — include the conf from the iphylo.org vhost, then:
apachectl configtest
sudo apachectl graceful
```

`apache-col-sparql.conf` lists the modules that must be enabled: `mod_proxy`,
`mod_proxy_http`, `mod_headers`, `mod_rewrite`. On macOS's bundled Apache these
are commented out in `/etc/apache2/httpd.conf` by default; Homebrew's lives at
`/opt/homebrew/etc/httpd/httpd.conf`.

`apachectl configtest` before `graceful` — a syntax error in an included conf
takes the whole of iphylo.org down, not just this endpoint.

**Stop the Mini sleeping**, or the endpoint vanishes mid-demo:

```sh
sudo pmset -a sleep 0 disksleep 0 womp 1
```

## Who can reach it

Open, unauthenticated, and announced to a few people — the current decision.
Serving from iphylo.org rather than behind Cloudflare changes what that means:

- **No Cloudflare Access.** The email-allowlist option is not available here.
  Apache basic auth would restrict access but breaks ordinary SPARQL clients,
  which do not expect an auth challenge.
- **No edge bot protection or WAF**, so crawlers will find it. `robots.txt`
  stops only the polite ones.
- **No 100s edge timeout.** `ProxyTimeout 90` in the conf is now the only limit,
  and per above it bounds the client's wait rather than Oxigraph's work.
- **No rate limiting.** See the note at the top.
- **Your home IP is exposed** — though it already is, by virtue of iphylo.org
  being served from this machine.

Given no memory ceiling and no real query timeout, the honest summary is that
one unbounded query from a stranger can make the Mini unhappy. That is an
acceptable risk for a proof of concept with a small audience; it would not be
for a URL posted widely. If it does become a problem, the lever with the best
ratio of effort to effect is putting iphylo.org behind Cloudflare's free tier
and enabling a rate-limiting rule — no change to any of this config.

## Checks

```sh
# local, bypassing Apache
curl -sG http://127.0.0.1:7878/query \
  --data-urlencode 'query=SELECT * WHERE { ?s ?p ?o } LIMIT 1' \
  -H 'Accept: application/sparql-results+json'

# through Apache
curl -sG https://iphylo.org/col/query --data-urlencode 'query=ASK { ?s ?p ?o }'

# writes refused
curl -si -X POST https://iphylo.org/col/update \
  --data-urlencode 'update=DROP ALL' | head -1   # expect 403

# CORS header present exactly once
curl -sI -G https://iphylo.org/col/query \
  --data-urlencode 'query=ASK { ?s ?p ?o }' | grep -ci access-control-allow-origin
# expect 1 — a 2 means --cors got passed to Oxigraph as well
```
