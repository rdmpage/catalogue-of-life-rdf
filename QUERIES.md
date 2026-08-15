# Example queries

Every query here has been run and timed against the live endpoints. Timings are
from a laptop over the open internet, so they include network round trips.

**Catalogue of Life** — `https://iphylo.org/col/query` (read-only, no auth)
**GBIF via QLever** — `https://qlever.dev/api/gbif`

---

## Federated: match GBIF ids to CoL ids

**Run this on QLever**, not on the CoL endpoint. QLever reaches out to us.

```sparql
PREFIX s: <https://schema.org/>

SELECT ?gbif ?col ?name WHERE {
  VALUES ?gbif {
    <https://www.gbif.org/species/5219404>
    <https://www.gbif.org/species/5687612>
    <https://www.gbif.org/species/7336815>
    <https://www.gbif.org/species/11552456>
    <https://www.gbif.org/species/9941559>
  }
  SERVICE <https://iphylo.org/col/query> {
    ?gbif s:sameAs ?col .
    ?col s:name ?name .
  }
}
```

| ?gbif | ?col | ?name |
|---|---|---|
| species/5219404 | taxon/4CGXP | *Panthera leo* |
| species/5687612 | taxon/3RDZT | *Krameria prostrata* |
| species/7336815 | taxon/5TD2P | *Molina* |
| species/11552456 | taxon/BCY37 | *Formosargus berezovskiyi* |
| species/9941559 | taxon/P5KTC | *Hesperoleucus symmetricus symmetricus* |

0.36s. `3RDZT` and `5TD2P` are synonyms — before the URI rework they had no
`.../taxon/{id}` resource and these lookups returned nothing.

### Joining GBIF occurrence data to CoL taxonomy

```sparql
PREFIX s: <https://schema.org/>
PREFIX dwc: <http://rs.tdwg.org/dwc/iri/>

SELECT ?occ ?colName ?status WHERE {
  VALUES ?gbif { <https://www.gbif.org/species/5219404> }
  ?occ dwc:toTaxon ?gbif .
  SERVICE <https://iphylo.org/col/query> {
    ?gbif s:sameAs ?col .
    ?col s:name ?colName ;
         <http://rs.tdwg.org/dwc/terms/taxonomicStatus> ?status .
  }
} LIMIT 5
```

0.5s. **Keep the GBIF side bounded.** The same query with
`(COUNT(*) AS ?occurrences) GROUP BY` instead of `LIMIT` timed out on QLever
with `Sort (internal order) on ?gbif` — *Panthera leo* alone has ~17,847
occurrences. The CoL side is cheap either way.

### Direction matters

QLever → CoL works with nothing configured on our side: it permits `SERVICE` to
arbitrary hosts and does proper bound joins, so a shared variable is pushed down
to the remote side.

CoL → QLever does **not** work out of the box. See `deploy/FEDERATION.md`.

---

## Local queries

Run these on `https://iphylo.org/col/query`. All are on the landing page too.

```sparql
PREFIX s: <https://schema.org/>
PREFIX dwc: <http://rs.tdwg.org/dwc/terms/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
```

### Everything about one taxon

```sparql
SELECT ?p ?o WHERE {
  <https://www.catalogueoflife.org/data/taxon/6MB3T> ?p ?o .
}
```

~150ms. `6MB3T` is *Homo sapiens*.

### The classification

```sparql
SELECT ?rank ?name WHERE {
  <https://www.catalogueoflife.org/data/taxon/6MB3T> s:parentTaxon* ?a .
  ?a s:name ?name ; s:taxonRank ?rank .
}
```

Walks to Eukaryota in one property path. Results are unordered — rank is a
string, not a sortable hierarchy.

### Children of a genus

```sparql
SELECT ?taxon ?name ?status WHERE {
  ?g s:name "Homo" ; s:taxonRank "Genus" .
  ?taxon s:parentTaxon ?g ;
         s:name ?name ;
         dwc:taxonomicStatus ?status .
}
```

Synonyms have no `parentTaxon`, so they cannot appear here.

### Synonyms of a taxon

```sparql
SELECT ?synonym ?status WHERE {
  ?syn dwc:acceptedNameUsageID
         <https://www.catalogueoflife.org/data/taxon/6MB3T> ;
       s:name ?synonym ;
       dwc:taxonomicStatus ?status .
}
```

Synonyms are `schema:Taxon` records in their own right pointing back with
`dwc:acceptedNameUsageID`. There is no `schema:alternateName` on the accepted
taxon.

### Cross-links to other databases

```sparql
SELECT ?name ?link WHERE {
  ?t a s:Taxon ; s:name ?name ; s:scientificName ?n .
  ?n s:seeAlso ?link .
  FILTER(CONTAINS(STR(?link), "boldsystems"))
} LIMIT 50
```

Links hang off the *name*, not the taxon. Targets include ITIS, BOLD,
MolluscaBase and Index Fungorum.

### Species described since 2015, with DOIs

```sparql
SELECT ?species ?year ?doi WHERE {
  ?t a s:Taxon ; s:taxonRank "Species" ;
     s:name ?species ; s:scientificName ?n .
  ?n s:isBasedOn ?ref .
  ?ref s:sameAs ?doi ; s:datePublished ?year .
  FILTER(xsd:integer(SUBSTR(STR(?year), 1, 4)) > 2015)
} LIMIT 50
```

Note the `SUBSTR` — see the datatype gotcha below.

---

## Gotchas

**`schema:Taxon` means *name usage*, not *accepted taxon*.** All 7,851,869 of
them, synonyms included. Filter on `dwc:taxonomicStatus`, and note
`"provisionally accepted"` (162,567 records) is distinct from `"accepted"` —
matching only `"accepted"` silently drops them.

**Dates have mixed datatypes.** `xsd:gYear` (1.2M), `xsd:date` (25K),
`xsd:gYearMonth` (1.7K), chosen by the precision available. So

```sparql
FILTER(?year > 2015)          # returns ZERO rows, silently
FILTER(xsd:integer(SUBSTR(STR(?year), 1, 4)) > 2015)   # works
```

Cross-datatype comparison raises type errors that filter everything out.

**Ranks are capitalised strings** — `"Species"`, `"Genus"`. Not IRIs.

**Names vs taxa.** The taxon is `.../taxon/{id}`; its name is
`.../taxon/{id}#name`. Nomenclatural facts (`seeAlso`, `isBasedOn`) live on the
name; taxonomic facts (`parentTaxon`, `isPartOf`, `taxonomicStatus`) on the
taxon.

**Bound your queries.** The endpoint runs on a Mac Mini. Oxigraph has no query
timeout, so an unbounded `ORDER BY` or `GROUP BY` over 102M triples will pin a
core until a watchdog kills it. Full scans take ~23s; bound lookups are
milliseconds.
