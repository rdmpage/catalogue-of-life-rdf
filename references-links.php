<?php

// Understand CoL literature links

error_reporting(E_ALL);

require_once(__DIR__ . '/shared.php');

$debug = true;

$basedir = '2026-05-15_xr_coldp';

// process references
$row_count = 0;

$filename = $basedir . '/Reference.tsv';

$mode = array(
	'bhl_link' 		=> 0, 
	'bhl_openurl' 	=> 0, 
	'bhl_like'  	=> 0,
	'pdf' 			=> 0, 
	'bionames' 		=> 0, 
	'aphia' 		=> 0, 
	'doi'			=> 0,
	'other' 		=> 0
);

$links = [];

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
				
			if (isset($data->{'col:link'}))
			{
				// BHL?
				if (preg_match('/biodiversitylibrary.org/', $data->{'col:link'}))
				{
					//echo $data->{'col:link'} . "\n";
					
					// OpenURL query
					if (preg_match('/openurl/', $data->{'col:link'}))
					{
						//echo $data->{'col:link'} . "\n";
						$mode['bhl_openurl']++;
					}
					// BHL 
					elseif (preg_match('/(\/bibliography|item|itempdf|page|part|partpdf|title)\/\d+/', $data->{'col:link'}))
					{
						$mode['bhl_link']++;
					}
					// BHL but not as we know it
					else
					{
						echo "Not recognised: |" . $data->{'col:link'} . "|\n";
						$mode['bhl_like']++;
					}
				}
				else
				{
					// PDF
					if (preg_match('/\.pdf$/', $data->{'col:link'}))
					{
						//echo $data->{'col:link'} . "\n";
						$mode['pdf']++;
						
						//$links[] = $data->{'col:link'};
					}
					// BioNames (!)
					elseif (preg_match('/bionames/', $data->{'col:link'}))
					{
						//echo $data->{'col:link'} . "\n";
						$mode['bionames']++;
					}
					// WoRMS family of databases
					elseif (preg_match('/aphia/', $data->{'col:link'}))
					{
						//echo $data->{'col:link'} . "\n";
						$mode['aphia']++;
						
						$links[] = $data->{'col:link'};
					}
					// DOI
					elseif (preg_match('/doi.org/', $data->{'col:link'}))
					{
						//echo $data->{'col:link'} . "\n";
						$mode['doi']++;
					}	
					// unclassified link		
					else
					{				
						//echo $data->{'col:link'} . "\n";
						$mode['other']++;
						
						$url_parts = parse_url($data->{'col:link'});
						if (!isset($mode[$url_parts['host']]))
						{
							$mode[$url_parts['host']] = 0;
						}
						$mode[$url_parts['host']]++;
					}
				}
			}
			
		}
	}

	$row_count++;	
}

arsort($mode);

print_r(array_slice($mode, 0, 10));

if (0)
{
	echo "\n\n'";
	echo join("',\n'", $links);
	echo "'\n\n";
}


?>
