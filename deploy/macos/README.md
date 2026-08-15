# Mac Mini proof of concept

Read-only Oxigraph on a Mac Mini M1 / 16 GB, exposed through a Cloudflare
Tunnel. No port forwarding, no dynamic DNS, no open inbound ports, and your
home IP stays hidden.

```
visitor ──HTTPS──> Cloudflare edge ──tunnel──> cloudflared ──> Caddy :8080 ──> oxigraph :7878
```

Everything on the Mini listens on loopback only. `cloudflared` makes an
*outbound* connection to Cloudflare, which is why nothing needs to be opened on
your router.

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
- **No query timeout exists in Oxigraph 0.4.2.** Caddy's 90s cap ends the
  *client's* wait, but Oxigraph keeps evaluating until it notices the closed
  socket. A runaway query keeps burning CPU after the visitor has gone.

For a handful of known people clicking around, both are acceptable. Neither is
acceptable for a URL posted publicly — see "Who can reach it" below.

## Disk

The Mini has **~500 GB free**, so disk is no longer a constraint. For reference:

| | |
|---|---|
| Bytes per triple (measured, 16 GiB / 75.68M) | 227 |
| Triples when all four files are loaded | 112,290,863 |
| Projected final store | **~24 GiB** |
| Two A/B slots plus the `.nt` files | ~62 GiB |
| All four `.nt` compressed with zstd (16.8x, measured) | ~0.8 GiB |

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
separate machine — but the plist and Caddyfile both expect the new layout.

## Setup

```sh
brew install caddy cloudflared

# 1. Oxigraph as a daemon (edit paths/UserName in the plist first)
sudo cp org.catalogueoflife.oxigraph.plist /Library/LaunchDaemons/
sudo chown root:wheel /Library/LaunchDaemons/org.catalogueoflife.oxigraph.plist
sudo launchctl bootstrap system /Library/LaunchDaemons/org.catalogueoflife.oxigraph.plist

# 2. Caddy
cp Caddyfile /opt/homebrew/etc/Caddyfile
brew services start caddy

# 3. Tunnel
cloudflared tunnel login
cloudflared tunnel create col-sparql
cloudflared tunnel route dns col-sparql sparql.example.org
# write the config below, then:
sudo cloudflared service install
```

`~/.cloudflared/config.yml`:

```yaml
tunnel: col-sparql
credentials-file: /Users/rpage/.cloudflared/<TUNNEL-UUID>.json

ingress:
  - hostname: sparql.example.org
    service: http://127.0.0.1:8080
  - service: http_status:404
```

**Stop the Mini sleeping**, or the endpoint vanishes mid-demo:

```sh
sudo pmset -a sleep 0 disksleep 0 womp 1
```

## Who can reach it

This is the decision that matters, and it depends on what you are demonstrating.

**If the demo is people running queries in a browser**, put Cloudflare Access in
front of the hostname and allow a list of email addresses. Visitors get a
one-time login link. This removes essentially every risk above, because only
people you named can send queries at all. Zero Trust → Access → Applications,
free for up to 50 users.

**If the demo is live federation from QLever**, you cannot use Access — a
`SERVICE` call carries no credentials and will just get an HTML login page back
where it expected SPARQL results. The endpoint has to be open, so instead:

- Turn **Bot Fight Mode off** for this hostname, or add a WAF skip rule.
  Otherwise Cloudflare will challenge QLever's requests and federation fails
  with a confusing HTML-instead-of-XML error.
- Confirm first that QLever's public instance will issue `SERVICE` to an
  arbitrary external host. Public endpoints often restrict outbound federation
  as an SSRF precaution, and if it is blocked, none of this setup is the reason
  the demo fails.
- Expect the 100s Cloudflare origin timeout (524) to be your effective query
  limit.

Given the 18s scan figure and no way to cap memory, I would run the browser
demo behind Access, and test QLever federation as a separate, scheduled,
watched-live exercise rather than leaving an open endpoint running on a home
machine indefinitely.

## Checks

```sh
# local, bypassing tunnel and Caddy
curl -sG http://127.0.0.1:7878/query \
  --data-urlencode 'query=SELECT * WHERE { ?s ?p ?o } LIMIT 1' \
  -H 'Accept: application/sparql-results+json'

# through Caddy
curl -sG http://127.0.0.1:8080/query --data-urlencode 'query=ASK { ?s ?p ?o }'

# writes refused
curl -si -X POST http://127.0.0.1:8080/update \
  --data-urlencode 'update=DROP ALL' | head -1   # expect 403

# end to end
curl -sG https://sparql.example.org/query --data-urlencode 'query=ASK { ?s ?p ?o }'
```
