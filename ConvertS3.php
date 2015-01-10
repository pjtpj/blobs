<?php

	// Copyright (C) 2015 Teztech, Inc.
	// All Rights Reserved
	
	require_once("www/config.php");
	require 'vendor/autoload.php';

	use Aws\S3\S3Client;
	
	if ($argc < 2)
		Usage();

	$command   = $argv[1];	
	$bucket    = $argc >= 3 ? $argv[2] : "mhodev";
	$filesRoot = $argc >= 4 ? $argv[3] : "files";
	
	if (($command != "copy" && $command != "testCopy") || 
		!file_exists($filesRoot))
		Usage();
		
	$s3client = S3Client::factory(array('key' => $AWS_KEY, 'secret' => $AWS_SECRET));		
	
	if ($hostHandle = @opendir($filesRoot)) 
	{
		while (false !== ($hostFolder = readdir($hostHandle ))) 
		{
			if ($hostFolder != "." && $hostFolder != ".." && $hostFolder != ".svn") 
			{
				echo "Processing folders for host: $hostFolder\n";
				
				$folderFolder = sprintf("%s/%s", $filesRoot, $hostFolder);				
				if ($folderHandle = @opendir($folderFolder)) 
				{
					while (false !== ($folder = readdir($folderHandle))) 
					{
						if ($folder != "." && $folder != ".." && $folder != ".svn") 
						{
							$originalsFolder = sprintf("%s/%s/%s/originals", $filesRoot, $hostFolder, $folder);
							
							echo "++> Processing originals in folder: $originalsFolder\n";
							
							if ($originalsHandle = @opendir($originalsFolder)) 
							{
								while (false !== ($subFolder = readdir($originalsHandle))) 
								{
									if ($subFolder != "." && $subFolder != ".." && $subFolder != ".svn") 
									{
										$filesFolder = sprintf("%s/%s/%s/originals/%s", $filesRoot, $hostFolder, $folder, $subFolder);
										
										echo "++++> Processing files in sub-folder: $filesFolder\n";
																				
										if ($filesFolderHandle = @opendir($filesFolder)) 
										{
											while (false !== ($fileName = readdir($filesFolderHandle))) 
											{
												$srcPath = sprintf("%s/%s", $filesFolder, $fileName);
																								
												if (is_file($srcPath)) 
												{
													$copyFile = True;
													try
													{
														$destHead = $s3client->headObject(array(
															'Bucket'     => $bucket,
															'Key'        => $srcPath
														));
														$srcLength = filesize($srcPath);
														if ($srcLength == $destHead['ContentLength'])
														{
															$copyFile = False;
															if ($command == "testCopy")
															{
																echo "--------> SKIPPING '$srcPath' - lengths are equal $srcLength\n";
															}
														}
													}
													catch (\Aws\S3\Exception\S3Exception $e)
													{
														// Copy file on error
														if ($command == "testCopy")
														{
															echo "Error getting head for '$srcPath'\n";
														}														
													}
												
													echo "--------> Copying '$srcPath' to 'S3/$filesFolder'\n";
													if ($copyFile)
													{
														if ($command == "copy")
														{
															$result = $s3client->putObject(array(
																'Bucket'     => $bucket,
																'Key'        => $srcPath,
																'SourceFile' => $srcPath
															));
														}
													}
												}
											}
											closedir($filesFolderHandle);
										}
									}
								}								
								closedir($originalsHandle);
							}
						}
					}
					closedir($folderHandle);
				}
				
				echo "\n";
			}
		}
		closedir($hostHandle);
	}
	
	exit();

function Usage()
{
	echo "USAGE: ConvertS3 copy|testCopy s3bucket filesRootDir\n";
	exit();
}

?>
