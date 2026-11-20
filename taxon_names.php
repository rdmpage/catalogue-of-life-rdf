<?php

// Export taxon names as triples

error_reporting(E_ALL);

require_once(__DIR__ . '/shared.php');

$debug = false;
$debug = true;

$basedir = '2026-10-16_XR';

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

// step 2 process taxonomic names
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
				//print_r($data);	
			}
			
			$triples = [];	
						
			// rows in database are taxonomic names
			$s = 'https://www.catalogueoflife.org/data/taxon/' . $data->{'col:ID'};
			$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
			$o = 'https://schema.org/TaxonName';
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
			
			// authorship col:authorship
			if (isset($data->{'col:authorship'}))
			{
				$authorship = $data->{'col:authorship'};
				
				$p = 'https://schema.org/author';
				$o = '"' . nice_literal($authorship) . '"';					
				$triples[] = [$s, $p, $o];					
			}				
	
			// rank
			if (isset($data->{'col:rank'}))
			{
				$rank = mb_convert_case($data->{'col:rank'}, MB_CASE_TITLE);
				
				$p = 'https://schema.org/taxonRank';
				$o = '"' . nice_literal($rank) . '"';					
				$triples[] = [$s, $p, $o];					
			}	
			
			// basionym
			// [col:basionymID] => PZ6R		
			if (isset($data->{'col:basionymID'}))
			{			
				$p = 'https://schema.org/seeAlso';
				$o = nice_uri('https://www.catalogueoflife.org/data/taxon/' . $data->{'col:basionymID'});					
				$triples[] = [$s, $p, $o];
			}
																
			// reference for name
			if (isset($data->{'col:nameReferenceID'}))
			{
				$p = 'https://schema.org/isBasedOn';
				$o = 'https://www.checklistbank.org/dataset/' . $datsetkey  . '/reference/' . $data->{'col:nameReferenceID'};					
				$triples[] = [$s, $p, $o];
			}
			
			// pointer to a name on a page, e.g. BHL
			// [col:namePublishedInPageLink] => https://www.biodiversitylibrary.org/page/33707833
			if (isset($data->{'col:namePublishedInPageLink'}))
			{
				if (preg_match('/www.biodiversitylibrary.org/', $data->{'col:namePublishedInPageLink'}))
				{			
					$p = 'https://schema.org/isBasedOn';
					$o = nice_uri($data->{'col:namePublishedInPageLink'});
					$triples[] = [$s, $p, $o];
				}
			}
			
			// this is a link to external source for the name,
			// sometimes this is a link to a name database that has persistent identifiers
			if (isset($data->{'col:link'}))
			{
				// [col:link] => http://www.indexfungorum.org/Names/NamesRecord.asp?RecordID=170970				
				if (preg_match('/https?:\/\/www.indexfungorum.org\/Names\/NamesRecord.asp\?RecordID=(\d+)/', $data->{'col:link'}, $m))
				{
					$p = 'https://schema.org/sameAs';
					$o = nice_uri('https://www.indexfungorum.org/Names/NamesRecord.asp?RecordID=' . $m[1]);					
					$triples[] = [$s, $p, $o];
				}
				
				// col:link] => https://doi.org/10.83108/rn.785479
				if (preg_match('/https?:\/\/doi.org\/10.83108\/rn/', $data->{'col:link'}, $m))
				{
					$p = 'https://schema.org/sameAs';
					$o = nice_uri($data->{'col:link'});					
					$triples[] = [$s, $p, $o];
				}
			
				/*
				$p = 'https://schema.org/seeAlso';
				$o = nice_uri($data->{'col:link'});					
				$triples[] = [$s, $p, $o];
				*/
			}
			
			// name in other taxonomic databases
			if (isset($data->{'col:alternativeID'}))
			{
				$alternative_ids = explode(",", $data->{'col:alternativeID'});
				foreach ($alternative_ids as $alternative_id)
				{
					if (preg_match('/(?<namespace>[a-z]+):(?<id>.*)$/', $alternative_id, $m))
					{
						switch ($m['namespace'])
						{
							case 'wfo':
								// clean off taxon (why do we have a mix of names and taxa?)
								// e.g. 7SMR wfo:wfo-0000120011-2026-06
								$identifier = $m['id'];
								$id = preg_replace('/-[0-9]{4}-[0-9]{2}$/', '', $identifier);
								
								$p = 'https://schema.org/sameAs';
								$o = nice_uri('https://list.worldfloraonline.org/' . $identifier);					
								$triples[] = [$s, $p, $o];
								break;
								
							case 'wikidata':							
								$p = 'https://schema.org/sameAs';
								$o = nice_uri('http://www.wikidata.org/entity/' . $m['id']);					
								$triples[] = [$s, $p, $o];														
								break;

							default:
								break;
						
						}
					}				
				}
			}
			
			// name in other name databases
			// col:nameAlternativeID
			if (isset($data->{'col:nameAlternativeID'}))
			{
				if (preg_match('/(?<namespace>[a-z]+):(?<id>.*)$/', $data->{'col:nameAlternativeID'}, $m))
				{
					switch ($m['namespace'])
					{
						case 'ipni':							
							$p = 'https://schema.org/sameAs';
							$o = nice_uri('https://www.ipni.org/n/' . $m['id']);					
							$triples[] = [$s, $p, $o];														
							break;
							
						// [col:nameAlternativeID] => zoobank:be8d39f1-a554-40b0-851f-52293af494c3
						case 'zoobank':							
							$p = 'https://schema.org/sameAs';
							$o = nice_uri('https://zoobank.org/' . $m['id']);					
							$triples[] = [$s, $p, $o];														
							break;
					
						default:
							break;
					
					}
				}				
			}
			
			
			$output = dump_triples($triples);			
			echo $output . "\n";			
		}
	}
	
	$row_count++;
	
	if ($debug)
	{
		if ($row_count == 10000)
		{
			exit();
		}
	}
	
}

?>
