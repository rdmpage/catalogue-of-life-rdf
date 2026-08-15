# Catalogue of Life in RDF

A version of the Catalogue of Life in RDF.

The Catalogue of Life web site often has JSON-LD embedded, some examples are in [col-website-jsonld](col-website-jsonld). For more examples, and a survey of JSON-LD in biodiversity-related web sites, see also [https://github.com/rdmpage/wild-json-ld](JSON-LD in the wild).

However, what I want is a complete version of the Catalogue of Life in RDF (without having to scrap all the web pages!). 

I follow the JSON-LD in the web pages reasonably closely, but with an emphasis on providing identifiers and cross links where ever possible. We also have a GBIF - CoL identifier mapping. The vocabulary is almost exclusively [schema.org](https://schema.org) with some enhancements from [Bioschemas](https://bioschemas.org).


## Source databases

GBIF source databases are instances of `schema:Dataset`.

## References

RDF for references is generated from the CSL-JSON in the CoL data dump. This is of variable quality, partly due to inadequacies of the parser CoL use, and also the often messy nature of taxonomic references. Every reference is an instance of `schema:CreativeWork`.

## Taxa and taxonomic names

Taxa and taxonomic names are modelled much as CoL currently does. I treat every record as an instance of `schema:Taxon`, that also has an instance of `schema:TaxonName` that has the same URI as the taxon, but with `#name` appended.

If a name is accepted it has a `schema:parentTaxon` that points to its parent in the classification, if not it has a `dwc:acceptedNameUsageID` that points to the accepted name.

`schema:TaxonName` may include a link to the external source of the name.

## GBIF Backbone → Catalogue of Life (COL 26.5 XR) identifier mapping

GBIF recently changed from its own internal taxonomic identifiers, which are integers, to using Catalogue of Life’s identifiers, which are alphanumeric (technically, they are LATIN29 encoded integers, see https://github.com/CatalogueOfLife/backend/issues/491#issuecomment-764526345).

As part of a discussion [Plazi taxon treatments no longer on GBIF?](https://discourse.gbif.org/t/plazi-taxon-treatments-no-longer-on-gbif/6402) @mdoering released a [mapping between the GBIF Backbone Taxonomy and the Catalogue of Life 26.5 Extended Release (COL 26.5 XR)](https://download.checklistbank.org/col/gbif/README.html). This mapping is in the [gbif-col-mapping](gbif-col-mapping) folder. The RDF version uses schema:sameAs to link GBIF legacy to CoL identifiers.


## Triple store

I use Oxigraph for experiments. In the root folder:

```
oxigraph serve -l oxigraph

```
This starts the oxigraph server (accessible on http://localhost:7878) and its files are store in the oxigraph folder.

To load triples (**note make sure you stop oxigraph before trying to load data**!):

```
oxigraph load --location oxigraph --file sources.nt
oxigraph load --location oxigraph --file gbif-col.nt
oxigraph load --location oxigraph --file references.nt
oxigraph load --location oxigraph --file taxa.nt

```

