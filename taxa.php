<?php

// Export taxa as triples


error_reporting(E_ALL);

require_once(__DIR__ . '/shared.php');

$debug = false;

$basedir = '2026-05-15_xr_coldp';

// step 1 get dataset key
$datsetkey = 0;

$filename = $basedir . '/metadata.yaml';

$handle = fopen($filename, "r");
$metadata = fread($handle, 100);
fclose($handle);

if (preg_match('/key:\s+(\d+)/', $metadata, $m))
{
	$datsetkey = $m[1];
}

// step 2 process references

$row_count = 0;

$filename = $basedir . '/NameUsage.tsv';

$file_handle = fopen($filename, "r");
while (!feof($file_handle)) 
{
	$line = trim(fgets($file_handle));
		
	$row = explode("\t",$line);
	
	$go = is_array($row) && count($row) > 1;
	
	if ($go)
	{
		if ($row_count == 0)
		{
			$headings = $row;	
		}
		else
		{
			$data = new stdclass;
		
		
			foreach ($row as $k => $v)
			{
				if (trim($v) != '' && $v != "None")
				{
					$data->{$headings[$k]} = $v;
				}
			}
		
			if ($debug)
			{
				print_r($data);	
			}
			
			/* {"@context":"https://schema.org/","@id":"https://www.catalogueoflife.org/data/taxon/7NNSP","@type":"Taxon","additionalType":["http://rs.tdwg.org/dwc/terms/Taxon","http://rs.tdwg.org/ontology/voc/TaxonConcept#TaxonConcept"],"identifier":[{"@type":"PropertyValue","name":"dwc:taxonID","propertyID":"http://rs.tdwg.org/dwc/terms/taxonID","value":"7NNSP"},{"@type":"PropertyValue","name":"col:ID","propertyID":"http://catalogueoflife.org/terms/ID","value":"7NNSP"}],"name":"Ameira Boeck, 1865","scientificName":{"@type":"TaxonName","name":"Ameira","author":"Boeck, 1865","taxonRank":"genus","isBasedOn":{"@type":"ScholarlyArticle","name":"Boeck, A. (1865). Oversigt over de ved Norges Kyster jagttagne Copepoder henhorende til Calanidernes, Cyclopidernes og Harpactidernes Familier. [Overview of the Copepods hunted on the coast of Norway belonging to the families Calanoidae, Cyclopoidae and Harpacticodidae.]. Forhandlinger I Videnskabs-Selskabet I Christiania, 1864: 226–282 [for 1864 however published in 1865]."}},"taxonRank":["https://api.checklistbank.org/vocab/rank/genus","genus"],"parentTaxon":{"@id":"https://www.catalogueoflife.org/data/taxon/7NFW9","@type":"Taxon","name":"Ameiridae","identifier":[{"@type":"PropertyValue","name":"dwc:taxonID","propertyID":"http://rs.tdwg.org/dwc/terms/taxonID","value":"7NFW9"}],"taxonRank":["https://api.checklistbank.org/vocab/rank/family","family"]}} 
			
			{"@context":"https://schema.org/","@id":"https://www.catalogueoflife.org/data/taxon/SH0795950.10FU","@type":"Taxon","additionalType":["http://rs.tdwg.org/dwc/terms/Taxon","http://rs.tdwg.org/ontology/voc/TaxonConcept#TaxonConcept"],"identifier":[{"@type":"PropertyValue","name":"dwc:taxonID","propertyID":"http://rs.tdwg.org/dwc/terms/taxonID","value":"SH0795950.10FU"},{"@type":"PropertyValue","name":"col:ID","propertyID":"http://catalogueoflife.org/terms/ID","value":"SH0795950.10FU"}],"name":"SH0795950.10FU","scientificName":{"@type":"TaxonName","name":"SH0795950.10FU","taxonRank":"unranked"},"taxonRank":["https://api.checklistbank.org/vocab/rank/unranked","unranked"],"parentTaxon":{"@id":"https://www.catalogueoflife.org/data/taxon/36RX5","@type":"Taxon","name":"Disa atricapilla","identifier":[{"@type":"PropertyValue","name":"dwc:taxonID","propertyID":"http://rs.tdwg.org/dwc/terms/taxonID","value":"36RX5"}],"taxonRank":["https://api.checklistbank.org/vocab/rank/species","species"]}}
			
			*/
			
			$triples = [];	
			
			$taxon_name_uri = '';
			
			// taxon
			$s = 'https://www.catalogueoflife.org/data/taxon/' . $data->{'col:ID'};
			$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
			$o = 'https://schema.org/Taxon';
			$triples[] = [$s, $p, $o];	
			
			// status
			$p = 'http://rs.tdwg.org/dwc/terms/taxonomicStatus';
			$o = '"' . nice_literal($data->{'col:status'}) . '"';			
			$triples[] = [$s, $p, $o];	
			
			// dataset source
			if (isset($data->{'col:sourceID'}))
			{		
				$p = 'https://schema.org/isPartOf';
				$o = 'https://www.catalogueoflife.org/data/dataset/' . $data->{'col:sourceID'};				
				$triples[] = [$s, $p, $o];	
			}
			
			// taxon name string
			$p = 'https://schema.org/name';
			$o = '"' . nice_literal($data->{'col:scientificName'}) . '"';			
			$triples[] = [$s, $p, $o];
		
			// rank
			if (isset($data->{'col:rank'}))
			{
				$rank = mb_convert_case($data->{'col:rank'}, MB_CASE_TITLE);
				
				$p = 'https://schema.org/taxonRank';
				$o = '"' . nice_literal($rank) . '"';					
				$triples[] = [$s, $p, $o];					
			}				
										
			// taxon name URI
			$taxon_name_uri = $s . '#name';	
			$p = 'https://schema.org/scientificName';								
			$triples[] = [$s, $p, $taxon_name_uri];
												
			// accepted taxon (= a node in the tree)
			if (in_array($data->{'col:status'}, array('accepted', 'provisionally accepted')))
			{
				// parent
				if (isset($data->{'col:parentID'}))
				{
					$p = 'https://schema.org/parentTaxon';
					$o = 'https://www.catalogueoflife.org/data/taxon/' . $data->{'col:parentID'};					
					$triples[] = [$s, $p, $o];
				}
								
			}
			elseif (in_array($data->{'col:status'}, array('synonym', 'ambiguous synonym', 'misapplied')))
			{
				if (isset($data->{'col:parentID'}))
				{
					$accepted = 'https://www.catalogueoflife.org/data/taxon/' . $data->{'col:parentID'}; // note use of parentID
					
					$p = 'http://rs.tdwg.org/dwc/terms/acceptedNameUsageID';						
					$triples[] = [$s, $p, $accepted];
				}
			}		
			else
			{
				// dont know what this is :O
				echo "Unknown status: "	. $data->{'col:status'} . "\n";
				exit();
			}
			
			// scientific name
			$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
			$o = 'https://schema.org/TaxonName';				
			$triples[] = [$taxon_name_uri, $p, $o];
			
			// name string
			$p = 'https://schema.org/name';
			$o = '"' . nice_literal($data->{'col:scientificName'}) . '"';				
			$triples[] = [$taxon_name_uri, $p, $o];
			
			// rank
			if (isset($data->{'col:rank'}))
			{
				$rank = mb_convert_case($data->{'col:rank'}, MB_CASE_TITLE);
				
				$p = 'https://schema.org/taxonRank';
				$o = '"' . nice_literal($rank) . '"';					
				$triples[] = [$taxon_name_uri, $p, $o];					
			}								
			
			// reference for name
			if (isset($data->{'col:nameReferenceID'}))
			{
				$p = 'https://schema.org/isBasedOn';
				$o = 'https://www.checklistbank.org/dataset/' . $datsetkey  . '/reference/' . $data->{'col:nameReferenceID'};					
				$triples[] = [$taxon_name_uri, $p, $o];
			}
			
			// link
			// this is a link to external source data, but may be a taxon or a name, depending
			// on the kind of database. Hence "sameAs" might be a bad choice, plus
			// a difficult one to make because we'd need to chose between sameAs for
			// the Taxon or the TaxonName. We play safe and liunk to the TaxonName.
			if (isset($data->{'col:link'}))
			{
				$p = 'https://schema.org/seeAlso';
				$o = nice_uri($data->{'col:link'});					
				$triples[] = [$taxon_name_uri, $p, $o];
			}
			
			$output = dump_triples($triples);			
			echo $output . "\n";			
		}
	}
	
	$row_count++;
	
	if ($debug)
	{
		if ($row_count == 1000000)
		{
			exit();
		}
	}
	
}

?>
