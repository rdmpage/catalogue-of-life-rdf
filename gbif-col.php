<?php

// Mapping between legacy GBIF taon ids and CoL ids


error_reporting(E_ALL);

require_once(__DIR__ . '/shared.php');

$debug = false;

$row_count = 0;

$filename = "gbif-col-mapping/gbif-col265.tsv";

$triples = [];

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
			
			$s = 'https://www.gbif.org/species/' . $data->{'gbif:ID'};
			$p = 'https://schema.org/sameAs';
			
			if (isset($data->{'col:ID'}))
			{
				$o = 'https://www.catalogueoflife.org/data/taxon/' . $data->{'col:ID'}; 
				$triples[] = [$s, $p, $o];	
				
				if (count($triples) > 100)
				{
					$output = dump_triples($triples);			
					echo $output . "\n";
					
					$triples = [];					
				}
			}
		}
	}
	
	$row_count++;
}

if (count($triples) > 0)
{
	$output = dump_triples($triples);			
	echo $output . "\n";					
}

?>
