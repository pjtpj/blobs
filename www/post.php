<?php

	// Copyright (C) 2008 Teztech, Inc.
	// All Rights Reserved
	
	require_once("config.php");
	require '../vendor/autoload.php';

	use Aws\S3\S3Client;

	$Action = isset($_REQUEST['Action']) ? $_REQUEST['Action'] : (isset($_REQUEST['Delete']) ? "Delete" : "");	
	
	if 
	(
		!isset($_REQUEST['Password']) || !isset($_REQUEST['Folder']) || ($Action != "ListFiles" && !isset($_REQUEST['File'])) ||
		$_REQUEST['Password'] == ""   || $_REQUEST['Folder'] == ""   || ($Action != "ListFiles" && $_REQUEST['File'] == "") 
	)
	{
		// Anything other than 200 is an error
		printf("201 (%s, %s, %s) Password, Folder and File arguments are required", $_REQUEST['Password'], $_REQUEST['Folder'], isset($_REQUEST['File']) ? $_REQUEST['File'] : "");
		exit();
	}

	$HostName = $_SERVER['HTTP_HOST'];
	$Password = $_REQUEST['Password'];
	$Folder   = $_REQUEST['Folder'];
	$File     = isset($_REQUEST['File']) ? $_REQUEST['File'] : "";
	
	// Check Password and get AccountName
	
	$sql    = "SELECT AccountName, Password FROM Accounts, AccountHosts WHERE HostName = '%s' AND Accounts.AccountID = AccountHosts.AccountID";
	$query  = sprintf($sql, mysql_real_escape_string($HostName));
	$result = mysql_query($query) or die( "ERROR: Cannot query database." );
	if (mysql_num_rows($result) != 1 )
	{
		print("202 Unknown username or bad password");
		exit();
	}
	$user = mysql_fetch_array($result, MYSQL_ASSOC);
	if ($user['Password'] != $Password)
	{
		print("202 Unknown username or bad password");
		exit();
	}
	
	$AccountName = $user['AccountName'];
	
	$dirCharsFolder  = $FILES_DIR_CHARS > 0 ? "/" . GetFolderName(pathinfo($File)) : "";	
	$originalsFolder = sprintf("%s/%s/%s/originals%s", $USE_S3 ? $S3_ROOT : $FILES_ROOT, $AccountName, $Folder, $dirCharsFolder);

	$s3client = $USE_S3 ? S3Client::factory(array('key' => $AWS_KEY, 'secret' => $AWS_SECRET, 'region' => $S3_REGION)) : NULL;
	
	// Process Action field
	
	if ($Action == "Status")
	{
		$File = MapFileName($File);
		$originalFile = sprintf("%s/%s", $originalsFolder, $File);
		if (($USE_S3 && !$s3client->doesObjectExist($S3_BUCKET, $originalFile)) || (!$USE_S3 && !file_exists($originalFile)))
		{
			printf("203 File '%s' does not exist", $originalFile);
			exit();
		}
		else
		{
			printf("200 File '%s' exists", $originalFile);
			exit();
		}
	}
	else if ($Action == "Delete")
	{
		// Delete the selected blob file and related cache files - this code is not specific to dcupload
		
		$File = MapFileName($File);
		if (!DeleteFiles($s3client, $AccountName, $Folder, $File))
		{
			printf("203 File '%s/%s' does not exist", $Folder, $File);
			exit();
		}
		else
		{
			printf("200 File was deleted");
			exit();
		}
	}	
	else if ($Action == "Rename")
	{
		// Rename the selected blob file (and delete related cache files) - this code is not specific to dcupload
		
		$File = MapFileName($File);
		$originalFile = sprintf("%s/%s", $originalsFolder, $File);
		if (($USE_S3 && !$s3client->doesObjectExist($S3_BUCKET, $originalFile)) || (!$USE_S3 && !file_exists($originalFile)))
		{
			printf("203 File '%s' does not exist", $originalFile);
			exit();
		}
		else if (!isset($_REQUEST['NewFile']) || $_REQUEST['NewFile'] == "")
		{
			print("204 NewFile argument is required");
			exit();
		}
		else
		{
			$NewFile = $_REQUEST['NewFile'];
			$NewFile = MapFileName($NewFile);
			$newDirCharsFolder  = $FILES_DIR_CHARS > 0 ? "/" . GetFolderName(pathinfo($NewFile)) : "";	
			$newOriginalsFolder = sprintf("%s/%s/%s/originals%s", $USE_S3 ? $S3_ROOT : $FILES_ROOT, $AccountName, $Folder, $newDirCharsFolder);
			$newOriginalFile = sprintf("%s/%s", $newOriginalsFolder, $NewFile);
			
			DeleteCacheFiles($s3client, $AccountName, $Folder, $File);

			if ($USE_S3)
			{
				$s3OriginalFile = sprintf("%s/%s", $S3_BUCKET, $originalFile);
				$s3client->copyObject(array(
					'Bucket'     => $S3_BUCKET,
					'Key'        => $newOriginalFile,
					'CopySource' => $s3OriginalFile
				));
				$s3client->deleteObject(array(
					'Bucket'     => $S3_BUCKET,
					'Key'        => $originalFile
				));
			}
			else
			{
				@mkdir($newOriginalsFolder, 0777, true);

				if (!@rename($originalFile, $newOriginalFile))
				{
					printf("205 Cannot rename '%s' as '%s'", $originalFile, $newOriginalFile);
					exit();
				}
			}

			printf("200 File '%s' was renamed as '%s'", $originalFile, $newOriginalFile);
			exit();
		}
	}	
	else if ($Action == "Update")
	{
		// Handle the .jpg file uploaded by dcupload

		// See http://www.php.net/features.file-upload for an explanation of PHP's file
		// upload support. There is nothing PHP specific about dcupload. No special
		// server module is required - the file is sent via a standard MIME encoded
		// HTTP POST. Any server lanaguage than can decode MIME encoded POST data
		// can use dccontrol.
		
		// The "browser" that submits this form to the server is either the dcupload 
		// control or and application (web, command line, GUI, etc.).
		// The control doesn't understand HTML - the only response it can handle
		// is a simple text only response - see below.
		
		// The name of the POST form variable used by dcupload to upload the 
		// image file's contents is always "userfile":
		if (!isset($_FILES['userfile']))
		{
			// Anything other than 200 is an error
			printf("203 No file was submitted: %s %s", print_r($_FILES, true), print_r($_REQUEST, true));
			exit();
		}
		
		$extensions    = explode(",", $EXTENSIONS);
		$fileParts     = pathinfo($_FILES['userfile']['name']);
		$fileExtension = strtolower($fileParts['extension']);
		if (!in_array($fileExtension, $extensions))
		{
			printf("203 Invalid file type '%s'", $fileExtension);
			exit();
		}

		if ($USE_S3)
		{
			// if (!file_exists($originalsFolder)) // file_exists very expensive on large folders
			@mkdir($originalsFolder, 0777, true);
		}
			
		if ($fileExtension == 'bmp')
			$File = MapFileName($File);
		
		$originalFile = sprintf("%s/%s", $originalsFolder, $File);
		$originalFileType = GetMimeType($originalFile);
		// if (file_exists($originalFile))
		DeleteFiles($s3client, $AccountName, $Folder, $File);

		$srcFile = $_FILES['userfile']['tmp_name'];
		
		if ($fileExtension == 'bmp')
		{
			$image = @imagecreatefrombmp($srcFile);
			if (!$image)
			{
				print("203 Cannot load '$srcFile' as BMP");
				// move_uploaded_file($srcFile, $originalFile);
				exit();
			}
			$pngFile = $USE_S3 ? tempnam(sys_get_temp_dir(), 'blobs_post') : $originalFile;
			if (! @imagepng($image, $pngFile))
			{
				print("203 Cannot save BMP '$srcFile' as PNG $pngFile");
				exit();
			}
			if ($USE_S3)
			{
				$s3client->putObject(array(
					'Bucket'      => $S3_BUCKET,
					'SourceFile'  => $pngFile,
					'Key'         => $originalFile,
					'ContentType' => $originalFileType
				));
				@unlink($pngFile);
			}
		}
		else
		{
			if ($USE_S3)
			{
				$s3client->putObject(array(
					'Bucket'      => $S3_BUCKET,
					'SourceFile'  => $srcFile,
					'Key'         => $originalFile,
					'ContentType' => $originalFileType
				));
			}
			else
			{
				if (!@move_uploaded_file($srcFile, $originalFile))
				{
					$errMsg = $php_errormsg;
					print("203 Cannot move file from '$srcFile' to '$originalFile'. The system error is: $errMsg");
					exit();
				}
			}
		}
	
		// 200 Response is required by dcupload control to indicate success
		//printf("200 The file '$srcFile' was successfully uploaded as '$originalFile' with MIME type '$originalFileType'.");
		printf("200 The file was successfully uploaded.");
		exit();
	}
	else if ($Action == "ListFiles")
	{
		// When the filesystem is out of space, you can end up with lots of 0 length files
		// that cause problems. The ideal solution would be to identify and delete those files
		// when ListFiles is called. However, at this time, the Photos folder contains about 
		// 1.4M files. It seems to be about impossible to iterate that folder, check the 
		// file size of every file and echo the names of the file back to stdio and keep
		// within reasonable time limits (we were getting about 20K files in 10 minutes).
		// The simplest solution is to remove the 0 length files before we try use ListFiles
		// for something like synchronizing blobs with a database. This can be done with a command
		// like this:
		//  find /home/biz/blobs/files/listingimages.t3city.com/Photos/originals/ -size 0 -type f -print0 | xargs -0 -I {} mv {} /home/biz/blobs/files/listingimages.t3city.com/Photos/tmp/
		
		set_time_limit(3600);
		$fileCount = 0;
		$originalsFolder = sprintf("%s/%s/%s/originals", $USE_S3 ? $S3_ROOT : $FILES_ROOT, $AccountName, $Folder);

		if ($USE_S3)
		{
			if ($FILES_DIR_CHARS > 0)
			{
				// See http://stackoverflow.com/questions/21138673/how-to-get-commonprefixes-from-an-amazon-s3-listobjects-iterator
				//trace(sprintf("Listing files in originalsFolder: %s\r\n", $originalsFolder));
				$folders1 = $s3client->getIterator(
					'ListObjects',
					array(
						"Bucket" => $S3_BUCKET,
						"Delimiter" => "/",
						"Prefix" => "$originalsFolder/"  // Trailing slash is required
					),
					array(
						'return_prefixes' => true,
					)
				);
				foreach ($folders1 as $folder1)
				{
					if (isset($folder1['Prefix']))
					{
						//trace(sprintf("Listing files in prefixFolder: %s\r\n", $folder1['Prefix']));
						$folders2 = $s3client->getIterator(
							'ListObjects',
							array(
								"Bucket" => $S3_BUCKET,
								"Delimiter" => "/",
								"Prefix" => $folder1['Prefix']
							)
						);
						foreach ($folders2 as $folder2)
						{
							if (isset($folder2['Key']))
							{
								//trace(sprintf("Found file: %s\r\n", $folder2['Key']));
								echo basename($folder2['Key']) . "\n";
								$fileCount++;
							}
						}
					}
				}
			}
			else
			{
				$folders1 = $s3client->getIterator(
					'ListObjects',
					array(
						"Bucket" => $S3_BUCKET,
						"Delimiter" => "/",
						"Prefix" => "$originalsFolder/"  // Trailing slash is required
					)
				);
				foreach ($folders1 as $folder1)
				{
					if (isset($folder1['Key']))
					{
						echo basename($folder1['Key']) . "\n";
						$fileCount++;
					}
				}
			}
		}
		else
		{
			if ($handle1 = opendir($originalsFolder))
			{
				while (false !== ($file1 = readdir($handle1)))
				{
					if ($file1 == "." || $file1 == "..")
						continue;

					$folder1 = sprintf("%s/%s", $originalsFolder, $file1);
					if (is_dir($folder1))
					{
						if ($handle2 = opendir($folder1))
						{
							while (false !== ($file2 = readdir($handle2)))
							{
								// trace("file2 = $file2\r\n");
								if ($file2 == "." || $file2 == "..")
									continue;

								echo "$file2\n";
								$fileCount++;
							}
						}
					}
					else
					{
						if (isset($folder1['Key']))
						{
							echo $folder1['Key'] . "\n";
							$fileCount++;
						}
					}
				}
			}
		}
		
		printf("200 %d files listed", $fileCount);
		exit();
	}
	
	printf("201 Unknown Action '%s'.", $Action);
	exit();
	
function GetFolderName($fileParts)
{
	global $FILES_DIR_CHARS;
	
	$fileName = is_array($fileParts) ? $fileParts["filename"] : $fileParts;
	$temp     = str_pad(str_replace(" ", "_", $fileName), $FILES_DIR_CHARS, "_", STR_PAD_LEFT);
	
	return strtolower(substr($temp, -$FILES_DIR_CHARS));
}

function DeleteFiles($s3client, $hostName, $folder, $file)
{
	global $USE_S3;
	global $S3_ROOT;
	global $S3_BUCKET;
	global $FILES_ROOT;
	global $FILES_DIR_CHARS;
	
	$dirCharsFolder  = $FILES_DIR_CHARS > 0 ? "/" . GetFolderName(pathinfo($file)) : "";
	$originalFile    = sprintf("%s/%s/%s/originals%s/%s", $USE_S3 ? $S3_ROOT : $FILES_ROOT, $hostName, $folder, $dirCharsFolder, $file);
	$cacheFolders    = sprintf("%s/%s/%s/cache", $USE_S3 ? $S3_ROOT : $FILES_ROOT, $hostName, $folder);

	if ($USE_S3)
	{
		// S3 doesn't seem to have a return code for successful file deletion
		if ($s3client->doesObjectExist($S3_BUCKET, $originalFile))
		{
			$dirCharsFolder2 = $FILES_DIR_CHARS > 0 ? GetFolderName(pathinfo($file)) . "/" : "";

			$s3client->deleteObject(array(
				'Bucket'     => $S3_BUCKET,
				'Key'        => $originalFile
			));

			$cacheFoldersI = $s3client->getIterator(
				'ListObjects',
				array(
					"Bucket"    => $S3_BUCKET,
					"Delimiter" => "/",
					"Prefix"    => $cacheFolders . "/"  // Trailing slash is required
				),
				array(
					'return_prefixes' => true,
				)
			);
			foreach ($cacheFoldersI as $cacheFolderI)
			{
				if (isset($cacheFolderI['Prefix']))
				{
					$filePath = sprintf("%s%s%s", $cacheFolderI['Prefix'], $dirCharsFolder2, $file);
					$s3client->deleteObject(array(
						'Bucket' => $S3_BUCKET,
						'Key' => $filePath
					));
				}
			}

			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	else
	{
		if (@unlink($originalFile))
		{
			if ($handle = @opendir($cacheFolders))
			{
				while (false !== ($cacheFolder = readdir($handle)))
				{
					if ($cacheFolder != "." && $cacheFolder != "..")
					{
						$filePath = sprintf("%s/%s%s/%s", $cacheFolders, $cacheFolder, $dirCharsFolder, $file);
						@unlink($filePath);
					}
				}
				closedir($handle);
			}

			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
}

function DeleteCacheFiles($s3client, $hostName, $folder, $file)
{
	global $USE_S3;
	global $S3_ROOT;
	global $S3_BUCKET;
	global $FILES_ROOT;
	global $FILES_DIR_CHARS;

	$dirCharsFolder  = $FILES_DIR_CHARS > 0 ? "/" . GetFolderName(pathinfo($file)) : "";
	$cacheFolders    = sprintf("%s/%s/%s/cache", $USE_S3 ? $S3_ROOT : $FILES_ROOT, $hostName, $folder);

	if ($USE_S3)
	{
		$dirCharsFolder2 = $FILES_DIR_CHARS > 0 ? GetFolderName(pathinfo($file)) . "/" : "";

		$cacheFoldersI = $s3client->getIterator(
			'ListObjects',
			array(
				"Bucket"    => $S3_BUCKET,
				"Delimiter" => "/",
				"Prefix"    => $cacheFolders . "/"  // Trailing slash is required
			),
			array(
				'return_prefixes' => true,
			)
		);
		foreach ($cacheFoldersI as $cacheFolderI)
		{
			if (isset($cacheFolderI['Prefix']))
			{
				$filePath = sprintf("%s%s%s", $cacheFolderI['Prefix'], $dirCharsFolder2, $file);
				$s3client->deleteObject(array(
					'Bucket' => $S3_BUCKET,
					'Key' => $filePath
				));
			}
		}
	}
	else
	{
		if ($handle = @opendir($cacheFolders))
		{
			while (false !== ($cacheFolder = readdir($handle)))
			{
				if ($cacheFolder != "." && $cacheFolder != "..")
				{
					$filePath = sprintf("%s/%s%s/%s", $cacheFolders, $cacheFolder, $dirCharsFolder, $file);
					@unlink($filePath);
				}
			}
			closedir($handle);
		}
	}
}

function MapFileName($file)
{
	return preg_replace('/\.bmp$/', '.png', $file);
}

function GetFileName($filePath)
{
	$parts = Explode('/', $filePath);
	return $parts[count($parts) - 1];
}

function imagecreatefrombmp($p_sFile) 
{ 
	$file = fopen($p_sFile,"rb"); 
	$read = fread($file,10); 
	
	while (!feof($file) && ($read <> "")) 
		$read .= fread($file,1024); 

	$temp   = unpack("H*",$read); 
	$hex    = $temp[1]; 
	$header = substr($hex,0,108); 

	// Process the header 
	// Structure: http://www.fastgraph.com/help/bmp_header_format.html 
	if (substr($header,0,4)=="424d") 
	{ 
		// Cut it in parts of 2 bytes 
		$header_parts = str_split($header, 2); 

		// Get the width 4 bytes 
		$width = hexdec($header_parts[19].$header_parts[18]); 

		// Get the height 4 bytes 
		$height = hexdec($header_parts[23].$header_parts[22]); 

		unset($header_parts); 
	} 

	$x = 0; 
	$y = 1; 
	$image = imagecreatetruecolor($width,$height); 

	// Grab the body from the image 
	$body = substr($hex,108); 

	// Calculate if padding at the end-line is needed 
	// Divided by two to keep overview. 
	// 1 byte = 2 HEX-chars 
	$body_size   = (strlen($body) / 2); 
	$header_size = ($width * $height); 

	// Use end-line padding? Only when needed 
	$usePadding = ($body_size > ($header_size * 3) + 4); 

	// Using a for-loop with index-calculation instead of str_split to avoid large memory consumption 
	// Calculate the next DWORD-position in the body 
	for ($i = 0; $i < $body_size; $i += 3) 
	{ 
		// Calculate line-ending and padding 
		if ($x >= $width) 
		{ 
			// If padding needed, ignore image-padding 
			// Shift i to the ending of the current 32-bit-block 
			if ($usePadding) 
				$i += $width % 4; 

			// Reset horizontal position 
			$x = 0; 

			// Raise the height-position (bottom-up) 
			$y++; 

			// Reached the image-height? Break the for-loop 
			if ($y > $height) 
				break; 
		}

		// Calculation of the RGB-pixel (defined as BGR in image-data) 
		// Define $i_pos as absolute position in the body 
		$i_pos = $i * 2; 
		$r     = hexdec($body[$i_pos+4].$body[$i_pos+5]); 
		$g     = hexdec($body[$i_pos+2].$body[$i_pos+3]); 
		$b     = hexdec($body[$i_pos].$body[$i_pos+1]); 

		// Calculate and draw the pixel 
		$color = imagecolorallocate($image,$r,$g,$b); 
		imagesetpixel($image,$x,$height-$y,$color); 

		$x++; 
	} 

	unset($body); 

	return $image; 
}

function GetMimeType($filename)
{
	$fileParts     = pathinfo($filename);
	$fileExtension = strtolower($fileParts['extension']);

	switch ($fileExtension)
	{
		case "gif":
			return "image/gif";
		case "jpg":
			return "image/jpeg";
		case "bmp":
		case "png":
			return "image/png";
		case "pdf":
			return "application/pdf";
		case "swf":
			return "application/x-shockwave-flash";
		default:
			return NULL;
	}
}

$TraceFile = NULL;

function trace($msg)
{
	global $FILES_ROOT;
	global $TraceFile; 
	
	if ($TraceFile == NULL)
	{
		$logsFolder = sprintf("%s/logs", $FILES_ROOT);
		@mkdir($logsFolder, 0777, true);
		$traceFileName = sprintf("%s/trace.log", $logsFolder);
		$TraceFile = fopen($traceFileName, "a");
	}
	
	fwrite($TraceFile, $msg);
	fflush($TraceFile);
}

function traceClose()
{
	global $TraceFile;

	if ($TraceFile != NULL)
	{
		fclose($TraceFile);
		$TraceFile = NULL;
	}
}

function var_dump_ret($mixed = null) {
	ob_start();
	var_dump($mixed);
	$content = ob_get_contents();
	ob_end_clean();
	return $content;
}

?>
