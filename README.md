# Catalogue of Life in RDF

A version of the Catalogue of Life in RDF.

## GBIF Backbone → Catalogue of Life (COL 26.5 XR) identifier mapping

GBIF recently changed from its own internal taxonomic identifiers, which are integers, to using Catalogue of Life’s identifiers, which are alphanumeric (technically, they are LATIN29 encoded integers, see https://github.com/CatalogueOfLife/backend/issues/491#issuecomment-764526345).

As part of a discussion [Plazi taxon treatments no longer on GBIF?](https://discourse.gbif.org/t/plazi-taxon-treatments-no-longer-on-gbif/6402) @mdoering released a [mapping between the GBIF Backbone Taxonomy and the Catalogue of Life 26.5 Extended Release (COL 26.5 XR)](https://download.checklistbank.org/col/gbif/README.html). This mapping is in the [gbif-col-mapping](gbif-col-mapping) folder.

## Triple store

I use Oxigraph for experiments. In the root folder:

```
oxigraph serve -l oxigraph

```
This starts the oxigraph server (accessible on http://localhost:7878) and its files are store in the oxigraph folder.

To load triples (**note make sure you stop oxigraph before trying to load data**!):

```
oxigraph load --location oxigraph --file triples.nt
```
