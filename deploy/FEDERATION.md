# Federating Catalogue of Life with GBIF

Everything below was tested against a live Oxigraph 0.4.2 store and
`https://qlever.dev/api/gbif`. Numbers are measured, not estimated.

## The short version

Oxigraph **can** issue `SERVICE` calls, so federation does not depend on anyone
else configuring their endpoint to talk to us. We can drive it from our side.

Three things have to be right:

1. QLever must be asked for XML, not JSON (a one-header proxy shim).
2. `gbif-col.nt` must be loaded — it is the join key.
3. Bindings must be written *inside* the `SERVICE` block, or the query is 380x
   slower.

## 1. Oxigraph cannot parse QLever's JSON

Out of the box this fails:

```sparql
SELECT * WHERE {
  SERVICE <https://qlever.dev/api/gbif> { SELECT * WHERE { ?s ?p ?o } LIMIT 1 }
}
```

```
… "s":{"type":"uri","value":"https://www.gbif.org/occurrence/100776"}}
Unexpected JSON after the end of the bindings array
```

The cause is not `SERVICE` being unsupported — the same query against DBpedia
returns clean results. It is a results-format incompatibility:

- QLever's SPARQL-JSON has three top-level members: `head`, `results`, **`meta`**
  (`{"query-time-ms": 217, "result-size-total": 1}`).
- Oxigraph's parser accepts nothing after the bindings array closes
  (`lib/sparesults/src/json.rs:861`, `JsonInnerSolutionsParserState::AfterEnd`).

**Upgrading will not fix this.** That code is unchanged in `main`, and the
installed 0.4.2 is already five minor versions behind 0.5.9. An older changelog
entry ("Allows unknown keys in the objects present in the SPARQL JSON query
results", for Virtuoso) covers unknown keys *inside binding objects*, which is a
different case.

Arguably both sides are at fault — the SPARQL 1.1 Results JSON spec does not
sanction `meta`, but neither does it forbid extra members, and most parsers
tolerate them. Worth reporting upstream to Oxigraph.

### The fix

QLever's XML is clean and spec-conformant, and Oxigraph accepts XML — it just
prefers JSON. The Accept header is hardcoded at
`lib/oxigraph/src/sparql/http.rs:43`:

```rust
"application/sparql-results+json, application/sparql-results+xml"
```

No q-values, so QLever takes the first. A proxy that rewrites that one header
resolves it. The Caddy block is in `macos/Caddyfile` (`:9999`); queries then use
`SERVICE <http://127.0.0.1:9999/>`. Oxigraph makes the call server-side, so
remote users are unaffected.

## 2. The join key is the GBIF species key

GBIF occurrences carry **no `dwc:scientificName` literal**. They link out:

```
<https://www.gbif.org/occurrence/100776>
    <http://rs.tdwg.org/dwc/iri/toTaxon> <https://www.gbif.org/species/2398913> .
```

So joining on name strings is not an option. The join must go through GBIF
species keys — which is exactly what `gbif-col.nt` provides, 5,810,113 times:

```
<https://www.gbif.org/species/5219404>
    <https://schema.org/sameAs> <https://www.catalogueoflife.org/data/taxon/4CGXP> .
```

This makes `gbif-col.nt` the single most important file for the federation
demo, despite being one of the smallest. It must be loaded.

(It also contains 57,526 blank lines. Harmless — N-Triples permits them.)

## 3. Bind inside the SERVICE block — you have no choice

Oxigraph does **not** push bindings down into a `SERVICE` block, and this is a
deliberate design decision, not a missing optimisation. From the changelog:

> SPARQL optimizer: do not attempt to optimize SERVICE (keep them, do not push
> filter in them...). This was leading to a lot of subtle behavior changes.

So no version upgrade and no query rewrite will fix it. **The consequence is
that a single-query dynamic join from CoL out to GBIF is not possible on
Oxigraph.** This times out (tested, with `gbif-col.nt` loaded):

```sparql
?col a s:Taxon ; s:name ?name .
VALUES ?name { "Panthera leo" }
?gbif s:sameAs ?col .                        # resolves locally to species/5219404
SERVICE <http://127.0.0.1:9999/> {
  ?occ dwc:toTaxon ?gbif .                   # ?gbif arrives UNBOUND
}
```

Oxigraph evaluates the `SERVICE` block independently of the rest of the query,
so `?gbif` is unbound on the remote side and it attempts to pull every
`dwc:toTaxon` triple in GBIF. Worse, it keeps going after the client
disconnects: measured at 33-38% CPU with RSS climbing past 320 MB three minutes
after the caller gave up. Restarting the server is the only way to stop it.

The remote key must therefore be a literal in the query text.

Written naively, with `?gbif` bound outside:

```sparql
SERVICE <http://127.0.0.1:9999/> {
  SELECT ?gbif (COUNT(*) AS ?occurrences) WHERE { ?occ dwc:toTaxon ?gbif }
  GROUP BY ?gbif
}
```

QLever computes occurrence counts for **every species in GBIF — 4,178,812 rows**
— ships all of them, and Oxigraph joins locally to keep one. Measured: **66
seconds**.

With the value written inside the block:

```sparql
SERVICE <http://127.0.0.1:9999/> {
  SELECT (COUNT(*) AS ?occurrences) WHERE {
    ?occ dwc:toTaxon <https://www.gbif.org/species/5219404> .
  }
}
```

Measured: **0.172 seconds**. Same answer, 17,847 occurrences.

For a query driven by a taxon name rather than a hardcoded key, resolve the key
locally first and inject it — do not leave the remote pattern unbound.

## The working example

```sparql
PREFIX s: <https://schema.org/>
PREFIX dwc: <http://rs.tdwg.org/dwc/iri/>

SELECT ?name ?rank ?occurrences WHERE {
  ?col a s:Taxon ; s:name ?name ; s:taxonRank ?rank .
  VALUES ?name { "Panthera leo" }
  SERVICE <http://127.0.0.1:9999/> {
    SELECT (COUNT(*) AS ?occurrences) WHERE {
      ?occ dwc:toTaxon <https://www.gbif.org/species/5219404> .
    }
  }
}
```

```
name,rank,occurrences
Panthera leo,Species,17847
```

`gbif-col.nt` is loaded, so the key can be resolved locally — but per §3 it
cannot be handed to `SERVICE` within the same query. Resolve it first:

```sparql
PREFIX s: <https://schema.org/>
SELECT ?gbif WHERE {
  ?col a s:Taxon ; s:name "Panthera leo" .
  ?gbif s:sameAs ?col .
}
# -> https://www.gbif.org/species/5219404
```

…then splice that IRI into the federated query above. For the demo page this is
two `fetch` calls chained in JavaScript, which looks seamless to a viewer while
staying honest about the mechanism.

### If you want single-query dynamic joins

That needs an engine that implements bound joins for `SERVICE` — QLever does.
Which means the original direction (QLever federating *into* CoL) is
technically better for this specific pattern, at the cost of depending on
`qlever.dev`'s configuration. The two approaches are complementary rather than
redundant:

| | Oxigraph → QLever | QLever → Oxigraph |
|---|---|---|
| Under our control | yes | no |
| Needs the XML shim | yes | no |
| Dynamic single-query join | **no** | yes |
| Works today | yes, with literal keys | untested |

## What this changes

The original plan waited on `qlever.dev` to allow outbound `SERVICE` to an
arbitrary host — something we cannot control or even check from here. Driving
federation from our side removes that dependency entirely. Whether QLever can
call *us* is still worth knowing, but it is no longer on the critical path for
demonstrating that CoL and GBIF join up.
