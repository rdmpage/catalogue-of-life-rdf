# Rod’s queries

## Some references with links

```
PREFIX : <https://schema.org/>
SELECT *
WHERE {
  ?s a :CreativeWork .
  ?s :url ?url .
}
LIMIT 10
```

## Number of references

```
PREFIX : <https://schema.org/>
SELECT (COUNT(?reference) as ?c)
WHERE {
  ?reference a :CreativeWork
}
```

**2,031,506**

### How many references have DOIs?

DOIs are “sameAs” relationships

```
PREFIX : <https://schema.org/>
SELECT (COUNT(?x) as ?c)
WHERE {
  ?reference a :CreativeWork .
  ?reference :sameAs ?x
}
```

**93,877** 

### How many references have links?

Links are urls

```
PREFIX : <https://schema.org/>
SELECT (COUNT(?x) as ?c)
WHERE {
  ?reference a :CreativeWork .
  ?reference :url ?x
}
```

**272,221** 

## Count names with BHL links

```
PREFIX : <https://schema.org/>
PREFIX dwc: <http://rs.tdwg.org/dwc/terms/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
SELECT (COUNT(?url) AS ?count)
WHERE {
  ?work :url ?url .
  ?scientificName :isBasedOn ?work .
  FILTER(CONTAINS(STR(?url), "biodiversitylibrary.org"))
}
```

**85,795**

## View list of names with BHL links

```
PREFIX : <https://schema.org/>
PREFIX dwc: <http://rs.tdwg.org/dwc/terms/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
SELECT *
WHERE {
  ?work :url ?url .
  ?work :name ?title .
  ?scientificName :isBasedOn ?work .
  ?scientificName :name ?name .
  FILTER(CONTAINS(STR(?url), "biodiversitylibrary.org"))
}
LIMIT 1000
```

## Publications about species in a genus

Find child taxa and list publications about those taxa.

```
PREFIX : <https://schema.org/>
PREFIX dwc: <http://rs.tdwg.org/dwc/terms/>
SELECT DISTINCT ?reference ?title ?sameAs ?url
WHERE {
  VALUES ?taxon {
    <https://www.catalogueoflife.org/data/taxon/7NRQV>
  } ?child :parentTaxon ?taxon .
  ?child :name ?name .
  ?child :scientificName ?scientificName .
  ?scientificName ?isBasedOn ?reference .
  ?reference :name ?title .
  OPTIONAL {
    ?reference :sameAs ?sameAs .
  }
  OPTIONAL {
    ?reference :url ?url .
  }
}
```

## Names associated with a publication

```
PREFIX : <https://schema.org/>
select *
WHERE {
  ?scientificName :isBasedOn <https://www.checklistbank.org/dataset/315192/reference/b9b8025e-fa6c-4800-b911-e165fa2fe073> .
  ?scientificName :name ?name .
}
```

## References published in a given year

```
PREFIX : <https://schema.org/>
SELECT *
WHERE {
  ?reference a :CreativeWork .
  ?reference :name ?title .
  ?reference :datePublished ?date .
  OPTIONAL {
    ?reference :url ?url .
  }
  FILTER(YEAR(?date) = 1880)
}
LIMIT 100
```

## Federated query showing GBIF map

```
PREFIX s: <https://schema.org/>
PREFIX dwciri: <http://rs.tdwg.org/dwc/iri/>
PREFIX wdt: <http://www.wikidata.org/prop/direct/>

SELECT * WHERE {
	VALUES ?col { <https://www.catalogueoflife.org/data/taxon/D59MS> }
	
  SERVICE <https://iphylo.org/col/query> {
    ?gbif s:sameAs ?col .
   }
	
?occurrence dwciri:toTaxon ?gbif .
?occurrence wdt:P625 ?pt .
}
LIMIT 100
```

## References in a given database

In Afromoths

```
PREFIX : <https://schema.org/>
SELECT ?reference ?title ?date ?url
WHERE {
  ?taxon :isPartOf <https://www.catalogueoflife.org/data/dataset/2017> .
  ?taxon :scientificName ?scientificName .
  ?scientificName :isBasedOn ?reference .
  ?reference :name ?title .
  ?reference :datePublished ?date .
  ?reference :url ?url .
}
```

## Find reference for taxon and all synonyms

```
PREFIX dwc: <http://rs.tdwg.org/dwc/terms/>
PREFIX : <https://schema.org/>

SELECT ?scientificName ?name ?title ?url
WHERE {
  {
    <https://www.catalogueoflife.org/data/taxon/65TDS> :scientificName ?scientificName .
    ?scientificName :name ?name .
    ?scientificName :isBasedOn ?reference .
    ?reference :name ?title .
    OPTIONAL {
      ?reference :url ?url .
    }
  }
  UNION {
    ?synonym dwc:acceptedNameUsageID <https://www.catalogueoflife.org/data/taxon/65TDS> .
    ?synonym :scientificName ?scientificName .
    ?scientificName :name ?name .
    ?scientificName :isBasedOn ?reference .
    ?reference :name ?title .
    OPTIONAL {
      ?reference :url ?url .
    }
  } 
}
```
