<?php

// Export references as triples

// Each reference has a URI based on the current CoL datasey key and the reference identifier

// A few crazies to deal with

// https://www.checklistbank.org/dataset/315192/reference/e083cd0c-3042-4c7b-b500-16a08baa12c3 
// is two references concatenated together

// https://www.checklistbank.org/dataset/315192/reference/65396482-98e3-4ee1-b4ca-dbbb11c816c5
// has the URL "ttps://biodiversitylibrary.org/page/27878232" (missing the h from http)

// The CSL parsing in CoL is often unreliable, dates and authors can be missing, containers
// can include volume and page numbers.


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

$filename = $basedir . '/Reference.jsonl';

$file_handle = fopen($filename, "r");
while (!feof($file_handle)) 
{
	$json = trim(fgets($file_handle));
	
	$obj = json_decode($json);
	
	if ($obj)
	{
		if ($debug)
		{
			print_r($obj);
		}
	
		$triples = [];	
		
		// everything is a creative work
		$s = 'https://www.checklistbank.org/dataset/' . $datsetkey  . '/reference/' . $obj->id;
		$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
		$o = 'https://schema.org/CreativeWork';
	
		$triples[] = [$s, $p, $o];	
		
		
		// date
		if (isset($obj->issued) && isset($obj->issued->{'date-parts'}) && isset($obj->issued->{'date-parts'}[0]))
		{
			$p = 'https://schema.org/datePublished';
			$o = nice_date($obj->issued->{'date-parts'}[0]);
		
			$triples[] = [$s, $p, $o];
		}
		
		// author
		if (isset($obj->author))
		{
			foreach ($obj->author as $index => $agent)
			{
				if (isset($agent->given) && isset($agent->family))
				{		
					$agent_id = $s . '#' . md5(json_encode($agent));				
					
					// person
					$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
					$o = 'https://schema.org/Person';
					$triples[] = [$agent_id , $p, $o];																					
					
					// names (these values may end up being repeated throught the triple store)
					$p = 'https://schema.org/givenName';
					$o = '"' . nice_literal($agent->given) . '"';
					$triples[] = [$agent_id , $p, $o];

					$p = 'https://schema.org/familyName';
					$o = '"' . nice_literal($agent->family) . '"';
					$triples[] = [$agent_id , $p, $o];
					
					// link to dataset (now that we have agent id
					$p = 'https://schema.org/creator';
					$o = nice_uri($agent_id);
					$triples[] = [$s, $p, $o];																									
				}
			}
		}
		
		// title
		if (isset($obj->title))
		{
			if (isset($obj->author) || isset($obj->issude))
			{
				// parsed reference
				$p = 'https://schema.org/name';
				$o = '"' . nice_literal($obj->title) . '"';
				
				$triples[] = [$s, $p, $o];		
			}
			else
			{
				// unparsed reference
				$p = 'https://schema.org/description';
				$o = '"' . nice_literal($obj->title) . '"';
				
				$triples[] = [$s, $p, $o];				
			}		
		
		}
		
		// page (often not properly parsed)
		if (isset($obj->page))
		{
			$p = 'https://schema.org/pagination';
			$o = '"' . nice_literal($obj->page) . '"';
			
			$triples[] = [$s, $p, $o];				
		}
		
		// container-title (often not properly parsed)
		if (isset($obj->{'container-title'}))
		{
			if (isset($obj->title))
			{
				// reference is at least partially parsed
				if (isset($obj->volume) || isset($obj->page))
				{
					// assume container-title is an actual container
				}
				else
				{
					// assume container title has not been parsed
					
				}
			}
			else
			{
				// we don't have a title so treat this as description 
				$p = 'https://schema.org/description';
				$o = '"' . nice_literal($obj->{'container-title'}) . '"';
				
				$triples[] = [$s, $p, $o];							
			}
		}
		
		if (isset($obj->DOI))
		{
			$p = 'https://schema.org/sameAs';
			$o = nice_uri('https://doi.org/' . $obj->DOI);
			
			$triples[] = [$s, $p, $o];
		}
		
		if (isset($obj->URL))
		{
			// sanity check
			if (preg_match('/^ttp/', $obj->URL))
			{
				$obj->URL = 'h' . $obj->URL;
			}
		
			$p = 'https://schema.org/url';
			$o = nice_uri($obj->URL);
			
			$triples[] = [$s, $p, $o];
		}
		
		
		$output = dump_triples($triples);			
		echo $output . "\n";
		
	}

	$row_count++;
	
	if ($debug)
	{
		if ($row_count == 100)
		{
			exit();
		}
	}
}


?>
