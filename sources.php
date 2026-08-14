<?php

// Parse source information for datasets and output as triples

// Source is modelled as a dataset

error_reporting(E_ALL);

require_once(__DIR__ . '/shared.php');
require_once(__DIR__ . '/vendor/autoload.php');

use Symfony\Component\Yaml\Yaml;

$debug = false;

// CoL
$basedir = '2026-05-15_xr_coldp/source';
$files = scandir($basedir);

if ($debug)
{
	$files=['1005.yaml'];
}

foreach ($files as $filename)
{
	if (preg_match('/\.yaml$/', $filename))
	{	
		$source = Yaml::parseFile($basedir . '/' . $filename, Yaml::PARSE_DATETIME);
		
		if ($debug)
		{
			print_r($source);
		}
		
		// triples
		$triples = array();
		
		$s = 'https://www.catalogueoflife.org/data/dataset/' . $source['key'];
		
		$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
		$o = 'https://schema.org/Dataset';
		
		$triples[] = [$s, $p, $o];									
		
		foreach ($source as $key => $value)
		{
			switch ($key)
			{
				case 'doi':
					$p = 'https://schema.org/sameAs';
					$o = 'https://doi.org/' . $value;
					
					$triples[] = [$s, $p, $o];									
					break;
				
				case 'title':
					$p = 'https://schema.org/name';
					$o = '"' . nice_literal($value) . '"';
					
					$triples[] = [$s, $p, $o];
					break;					
				
				/*
					// These can be big blobs of text
				case 'description':
					$p = 'https://schema.org/description';
					$o = '"' . nice_literal($value) . '"';
					
					$triples[] = [$s, $p, $o];									
					break;
				*/
					
				case 'url':
					$p = 'https://schema.org/url';
					$o = nice_uri($value);
					
					$triples[] = [$s, $p, $o];					
					break;
					
				case 'logo':
					$p = 'https://schema.org/image';
					$o = nice_uri($value);
					
					$triples[] = [$s, $p, $o];					
					break;
										
					// we have set Yaml::PARSE_DATETIME flag so this should be a DateTime object
				case 'issued':
					if (is_object($value))
					{
						$date_value = $value->format('Y-n-j');
					
						$p = 'https://schema.org/datePublished';
						$o = nice_date($date_value);
					
						$triples[] = [$s, $p, $o];
					}
					break;
										
				case 'creator':
				case 'contributor':	
				case 'editor':					
					foreach ($value as $index => $agent)
					{
						// invent an identifier
						$agent_id = $s . '#' . md5(json_encode($agent));
					
						if (isset($agent['given']) && isset($agent['family']))
						{							
							// Might have an ORCID, which we will use as the identifier
							if (isset($agent['orcid']))
							{
								$agent_id = 'https://orcid.org/' . $agent['orcid'];
							}
							
							// person
							$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
							$o = 'https://schema.org/Person';
							$triples[] = [$agent_id , $p, $o];																					
							
							// names (these values may end up being repeated throught the triple store)
							$p = 'https://schema.org/givenName';
							$o = '"' . nice_literal($agent['given']) . '"';
							$triples[] = [$agent_id , $p, $o];

							$p = 'https://schema.org/familyName';
							$o = '"' . nice_literal($agent['family']) . '"';
							$triples[] = [$agent_id , $p, $o];
						}
						else
						{
							// Might have a ROR, which we will use as the identifier
							if (isset($agent['rorid']))
							{
								$agent_id = 'https://ror.org/' . $agent['rorid'];
							}
						
							// organisation
							$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
							$o = 'https://schema.org/Organization'; // note the "z"
							$triples[] = [$agent_id , $p, $o];		
							
							if (isset($agent['organisation']))
							{
								// name (this value may end up being repeated throught the triple store)
								$p = 'https://schema.org/name';
								$o = '"' . nice_literal($agent['organisation']) . '"';
								$triples[] = [$agent_id , $p, $o];
							}																			
						}
						
						// link to dataset (now that we have agent id
						$p = 'https://schema.org/' . $key;
						$o = nice_uri($agent_id);
						$triples[] = [$s, $p, $o];																				
					}
					break;
						
				default:
					break;
			}
		}
		
		$output = dump_triples($triples);
		
		echo $output . "\n";
	}
}

?>
