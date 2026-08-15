# Working on this repo

Context for an assistant picking this up fresh. Written 2026-08-15 at the point
the work moved from a laptop to a Mac Mini.

## What this is

Catalogue of Life expressed as RDF using schema.org plus Bioschemas, loaded into
Oxigraph, with the intention of serving it as a public SPARQL endpoint. See
`README.md` for the modelling rationale — read it before touching anything that
generates triples.

## The state of play — read this first

**Rod is reworking the taxon/name modelling. Do not redesign it, do not "fix" the
URI scheme, and do not edit `taxa.php` unless asked.** He is separating how
*records* are modelled from how a *summary* view should be built (likely via
`CONSTRUCT`). Treat the model as in flux.

A rebuilt `taxa.nt` landed 2026-08-15 (renamed from `taxon.nt`; 8.1 GB →
11.1 GB, 75,580,884 → **96,326,172** triples). Observed shape, which differs from
what `README.md` describes — trust the data over the prose until Rod updates it:

```
<.../taxon/H9TCH>        a schema:Taxon ;
    dwc:taxonomicStatus  "accepted" ;
    schema:isPartOf      <.../dataset/30477> ;
    schema:name          "SH0134369.10FU" ;
    schema:taxonRank     "Unranked" ;
    schema:scientificName <.../taxon/H9TCH#name> ;
    schema:parentTaxon   <.../taxon/4RCCG> .

<.../taxon/H9TCH#name>   a schema:TaxonName ;
    schema:name          "SH0134369.10FU" ;
    schema:taxonRank     "Unranked" ;
    schema:seeAlso       <http://dx.doi.org/10.15156/BIO/SH0134369.10FU> .
```

Two changes from the old scheme: the name URI is `#name` rather than `#{id}`, and
every name usage now carries `dwc:taxonomicStatus`. **Re-verify counts and query
shapes against the store rather than reusing anything below.** In particular
`?s a schema:Taxon` no longer necessarily means "accepted taxon" — filter on
`dwc:taxonomicStatus` if you mean accepted.

Why it changed: the GBIF→CoL mapping in `gbif-col.nt` points at `.../taxon/{id}`
for *every* CoL identifier, but the old scheme minted that URI only for accepted
taxa — synonyms existed solely at `.../taxon/{id}#{id}`. So 2,625,026 of
5,810,113 mappings (45%) pointed at a URI with no statements. Verified by
sampling: those targets are synonyms (`Krameria prostrata`, `Molina`,
`Halosauropsis gracilis`), not a CoL version mismatch.

One constraint found while scoping the fix, worth knowing if it recurs: **there
is no IRI-valued Darwin Core term for "accepted name usage"**. Both
`dwc:acceptedNameUsage` and `dwc:acceptedNameUsageID` are literal-valued, and the
`dwciri:` namespace (~70 terms, including `toTaxon` and `identifiedBy`) has no
`acceptedNameUsage`. `dwc:taxonomicStatus` does exist and is literal-valued.

### Current file sizes

**Every record block ends with a blank line**, so line counts overstate triples
by ~10M. N-Triples permits this and Oxigraph skips them, but do not use `wc -l`
as a triple count.

| File | Lines | Blank | Real triples |
|---|---|---|---|
| `taxa.nt` | 96,326,172 | 7,851,869 | 88,474,303 |
| `references.nt` | 9,713,301 | 2,031,506 | 7,681,795 |
| `gbif-col.nt` | 5,867,639 | 57,526 | 5,810,113 |
| `sources.nt` | 383,751 | 20,976 | 362,775 |
| **total** | 112,290,863 | 9,961,877 | **102,328,986** |

Loaded on the Mini: **102,308,859** — 20,127 fewer, i.e. 0.02% genuine duplicate
triples that RDF dedupes. The store is **18 GB** on disk. Load took 2m57s,
`optimize` a further 1m32s.

## Layout

| Path | What |
|---|---|
| `taxa.php`, `references.php`, `sources.php`, `gbif-col.php` | Generate N-Triples from the ColDP dump. Share helpers in `shared.php`. |
| `2026-05-15_xr_coldp/` | ColDP source data, 5.0 GB. **Gitignored — will not be in a fresh checkout.** Copy it separately. |
| `*.nt` | Generated RDF, ~13 GB. Gitignored. Regenerate with the PHP scripts. |
| `oxigraph/` | RocksDB store (dev only). Gitignored. On the Mini it lives at `~/oxigraph-col`, outside the web root. Rebuild, never copy between machines. |
| `index.html` | The SPARQL web interface. At the repo root because the checkout is served directly on the Mini. |
| `deploy/` | Hosting configs. Linux (systemd + Caddy) at the top; macOS (launchd + **Apache**) in `deploy/macos/`. |
| `deploy/FEDERATION.md` | Everything learned about Oxigraph↔QLever federation. |
| `col-website-jsonld/` | Examples of JSON-LD scraped from the CoL website. |

## Running Oxigraph

```sh
oxigraph serve -l oxigraph          # dev, read-write, localhost:7878
oxigraph load --location oxigraph --lenient -f taxa.nt references.nt gbif-col.nt sources.nt
```

**Stop the server before loading.** `oxigraph load` is a writer, and Oxigraph
documents opening a store read-only while another process writes it as undefined
behaviour. Concurrent *readers* of one store are fine and explicitly supported.

Installed version is 0.4.2 (Homebrew); current upstream is 0.5.9.

### Two Oxigraph behaviours that will waste your time

**There is no query timeout, and abandoned queries keep running.** 0.4.2's only
serve flags are `--location`, `--bind`, `--cors`, `--union-default-graph`. A
runaway query was measured still burning 33–38% CPU with RSS climbing past
320 MB three minutes after the client disconnected. If you fire an expensive
query and give up, the server is still working on it. Restarting is the only
reliable stop. **Be careful with unbounded queries against this store.**

**Oxigraph never pushes bindings into a `SERVICE` block.** This is deliberate,
per the changelog: "do not attempt to optimize SERVICE (keep them, do not push
filter in them...)". So a variable shared between the local query and a `SERVICE`
block arrives *unbound* remotely. Consequences and the workaround are in
`deploy/FEDERATION.md`.

## Data gotchas

**`schema:datePublished` has mixed datatypes** — `xsd:gYear` (1,200,689),
`xsd:date` (25,207), `xsd:gYearMonth` (1,695), chosen by date precision in
`shared.php`. So `FILTER(?y > 2015)` silently returns **zero rows**: cross-datatype
comparison raises type errors that filter everything out. Use
`FILTER(xsd:integer(SUBSTR(STR(?y), 1, 4)) > 2015)`. There is at least one odd
value, `"-201"^^xsd:gYear`.

**Ranks are capitalised strings** — `"Species"`, `"Genus"`. Not IRIs.

**`gbif-col.nt` contains 57,526 blank lines.** Harmless; N-Triples permits them.

**GBIF occurrences carry no `dwc:scientificName` literal.** They link via
`dwc:toTaxon` → `https://www.gbif.org/species/{key}`, so joining CoL to GBIF has
to go through GBIF species keys — which is exactly what `gbif-col.nt` provides.

## Performance, measured

On an M1 Pro / 32 GB against the *old* 75.7M-triple store. Re-measure after the
rebuild; treat these as orders of magnitude.

- Bound-subject lookups, property paths (`parentTaxon*`), genus children: **10–20 ms**
- `SELECT (COUNT(*)) WHERE { ?s ?p ?o }` (full index scan): **18 s**
- Single `?s a schema:Taxon` count: **~1 s**
- Storage: **~189 bytes/triple** (18 GB / 102.3M, measured on the Mini)
- zstd on N-Triples: **16.8x** (10.9 GB → 0.65 GB), so ship compressed and
  decompress at the far end

## Deployment

`deploy/README.md` (Linux/Hetzner) and `deploy/macos/README.md` (Mac Mini) are
current and self-contained. Both run read-only Oxigraph on loopback behind a
reverse proxy, with A/B store slots so a reload costs one service restart rather
than the length of a bulk load. The Mini has ~500 GB free; the laptop did not,
which is why the work moved.

**On the Mini the proxy is the existing Apache**, not Caddy — iphylo.org is
already served from that machine with TLS, so `apache-col-sparql.conf` publishes
the endpoint at `https://iphylo.org/col/query` with the page at `/col/`. The
`Caddyfile` there is a standalone alternative, unused. No Cloudflare tunnel is
involved.

**The QLever federation shim in `deploy/macos/Caddyfile` is commented out
deliberately. Do not enable it.** Rod wants to understand the interaction with
QLever before the server issues outbound SPARQL to a third party on behalf of
whoever queries the endpoint. `deploy/FEDERATION.md` explains what it did and why
it was needed.

**On the Mini the git checkout *is* the web directory** — cloned to
`~/Sites/iphylo/col`, so `index.html` at the repo root is served at
`https://iphylo.org/col/` and a `git pull` deploys. It derives its SPARQL
endpoint from its own URL, so it works at any mount point without edits.

Because of that, `apache-col-sparql.conf` **denies everything in the checkout
and allows only `index.html`**. If you add a file that should be public, add an
explicit allow — do not switch it to a deny-list. `.php` is blocked twice over:
the generators read the ColDP dump and emit millions of triples, so an executed
`taxa.php` over HTTP would be genuinely bad.

The Oxigraph store lives at `~/oxigraph-col`, outside the web root by design. Style brief: **understated,
Hugging Face-like**. An earlier ornate version was rejected — keep it plain.
Note the diagram's counts are hardcoded in the SVG and will go stale after the
rebuild; the header stats fetch live.

## Working style

- Verify against the running store or the actual source rather than asserting
  from memory. Nearly every useful finding in this repo came from running a
  query, not reasoning about one.
- Rod knows this domain far better than you do. On modelling questions, surface
  constraints and evidence, then let him decide.
