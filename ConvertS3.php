<?php

	// Copyright (C) 2015 Teztech, Inc.
	// All Rights Reserved

	require_once("www/config.php");
	require 'vendor/autoload.php';

	use Aws\S3\S3Client;

	if ($argc < 2)
		Usage();

	$command     = $argv[1];
	$region      = $argc >= 4 ? $argv[2] : $S3_REGION;
	$bucket      = $argc >= 3 ? $argv[3] : $S3_BUCKET;
	$filesRoot   = $argc >= 4 ? $argv[4] : $S3_ROOT;
	$maxCommands = 100;

	if (($command != "copy" && $command != "testCopy") ||
		!file_exists($filesRoot))
		Usage();

	$s3client = S3Client::factory(array('key' => $AWS_KEY, 'secret' => $AWS_SECRET, 'region' => $region));

	if ($hostHandle = @opendir($filesRoot))
	{
		while (FALSE !== ($hostFolder = readdir($hostHandle )))
		{
			if ($hostFolder != "." && $hostFolder != ".." && $hostFolder != ".svn")
			{
				echo "Processing folders for host: $hostFolder\n";

				$folderFolder = sprintf("%s/%s", $filesRoot, $hostFolder);
				if ($folderHandle = @opendir($folderFolder))
				{
					while (FALSE !== ($folder = readdir($folderHandle)))
					{
						if ($folder != "." && $folder != ".." && $folder != ".svn")
						{
							$originalsFolder = sprintf("%s/%s/%s/originals", $filesRoot, $hostFolder, $folder);

							echo "++> Processing originals in folder: $originalsFolder\n";

							if ($FILES_DIR_CHARS > 0)
							{
								if ($originalsHandle = @opendir($originalsFolder))
								{
									while (FALSE !== ($subFolder = readdir($originalsHandle)))
									{
										if ($subFolder != "." && $subFolder != ".." && $subFolder != ".svn")
										{
											$filesFolder = sprintf("%s/%s/%s/originals/%s", $filesRoot, $hostFolder, $folder, $subFolder);

											echo "++++> Processing files in sub-folder: $filesFolder\n";
											ProcessFilesFolder($command, $s3client, $bucket, $filesFolder, $maxCommands);
										}
									}
									closedir($originalsHandle);
								}
							}
							else
							{
								ProcessFilesFolder($command, $s3client, $bucket, $originalsFolder, $maxCommands);
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
	echo "USAGE: ConvertS3 copy|testCopy awsRegion s3bucket filesRootDir\n";
	exit();
}

function ProcessFilesFolder($command, $s3client, $bucket, $filesFolder, $maxCommands)
{
	if ($filesFolderHandle = @opendir($filesFolder))
	{
		$fileName = TRUE;

		while (FALSE !== $fileName)
		{
			$folderFiles = array();
			$s3Commands = array();
			$commands = 0;

			while (FALSE !== ($fileName = readdir($filesFolderHandle)))
			{
				$srcPath = sprintf("%s/%s", $filesFolder, $fileName);
				if (is_file($srcPath))
				{
					$folderFiles[$srcPath] = TRUE;
					$s3Commands[] = $s3client->getCommand('HeadObject', array(
						 'Bucket'     => $bucket,
						 'Key'        => $srcPath
					));
				}

				$commands++;
				if ($commands >= $maxCommands)
				  break;
			}

			$copyFile = TRUE;
			$s3Results = array();
			try
			{
				if (count($s3Commands))
					$s3Results = $s3client->execute($s3Commands);
			}
			catch (Guzzle\Service\Exception\CommandTransferException $e)
			{
				echo "Error getting AWS head for '$filesFolder'\n";
				$s3Results = $e->getAllCommands();
			}

			foreach ($s3Results as $s3Result)
			{
				if ($s3Result->getResponse()->isSuccessful())
				{
					//var_dump($s3Result->GetResponse()->GetInfo());
					$srcPath = $s3Result['Key'];
					$srcLength = filesize($srcPath);
					$destLength = $s3Result->GetResponse()->GetInfo()['download_content_length'];
					// echo sprintf("Key=%s srcLength=%d destLength=%d \n", $s3Result['Key'], $srcLength, $destLength);
					if ($srcLength == $destLength)
					{
						$folderFiles[$srcPath] = FALSE;
						if ($command == "testCopy")
						{
							echo "--------> SKIPPING '$srcPath' - lengths are equal $srcLength\n";
						}
					}
				}
			}

			$s3Commands = array();
			foreach ($folderFiles as $srcPath => $copyFile)
			{
				if ($copyFile)
				{
					echo "--------> Copying '$srcPath' to 'S3/$filesFolder'\n";
					$s3Commands[] = $s3client->getCommand('PutObject', array(
						 'Bucket'     => $bucket,
						 'Key'        => $srcPath,
						 'SourceFile' => $srcPath
					));
				}
			}

			if ($command == "copy")
			{
				$s3client->execute($s3Commands);
			}
		}

		closedir($filesFolderHandle);
	}
}

?>
