<?php

	// Copyright (C) 2008-2015 Teztech, Inc.
	// All Rights Reserved
	
	require_once("config.php");
	require '../vendor/autoload.php';

	use Aws\S3\S3Client;
	
	if (!isset($_REQUEST['Folder']) ||!isset($_REQUEST['File']))
		NotFound('File not found');
	
	$HostName = $_SERVER['HTTP_HOST'];
	$Folder   = $_REQUEST['Folder'];
	$File     = $_REQUEST['File'];
	
	// Check Password and get AccountName
	
	$sql    = "SELECT AccountName FROM Accounts, AccountHosts WHERE HostName = '%s' AND Accounts.AccountID = AccountHosts.AccountID";
	$query  = sprintf($sql, mysql_real_escape_string($HostName));
	$result = mysql_query($query) or die( "ERROR: Cannot query database." );
	if (mysql_num_rows($result) != 1)
		NotFound(sprintf("Host '%s' not found", $HostName));
	$user = mysql_fetch_array($result, MYSQL_ASSOC);
	$AccountName = $user['AccountName'];

	$fileParts      = pathinfo($File);
	$fileExtension  = strtolower($fileParts['extension']);
	$dirCharsFolder = $FILES_DIR_CHARS > 0 ? GetFolderName($fileParts) . "/" : "";
	
	if ($USE_S3)
	{
		$s3client        = S3Client::factory(array('key' => $AWS_KEY, 'secret' => $AWS_SECRET));
		$originalFile    = sprintf("%s/%s/%s/originals/%s%s", $S3_ROOT, $AccountName, $Folder, $dirCharsFolder, $File);
		$originalFileUrl = sprintf("%s/%s", $S3_URL, $originalFile);
		
		if (preg_match('/\-(\d+)x(\d+)/i', $fileParts['filename'], $matches))
		{
			$cxDest         = intval($matches[1]);
			$cyDest         = intval($matches[2]);
			$fileName       = substr($fileParts['filename'], 0, strlen($fileParts['filename']) - strlen($matches[0]));
			$dirCharsFolder = $FILES_DIR_CHARS > 0 ? GetFolderName($fileName) . "/" : "";
			$originalFile   = sprintf("%s/%s/%s/originals/%s%s.%s", $S3_ROOT, $AccountName, $Folder, $dirCharsFolder, $fileName, $fileExtension);
			$cacheFolder    = sprintf("%s/%s/%s/cache/%s%dx%d", $S3_ROOT, $AccountName, $Folder, $dirCharsFolder, $cxDest, $cyDest);
			$cacheFile      = sprintf("%s/%s.%s", $cacheFolder, $fileName, $fileExtension);
			$cacheFileUrl   = sprintf("%s/%s", $S3_URL, $cacheFile);
			
			if (!$s3client->doesObjectExist($S3_BUCKET, $cacheFile))
			{
				try
				{
					$result = $s3client->getObject(array(
						'Bucket'     => $S3_BUCKET,
						'Key'        => $originalFile
					));
					$imageData = (string)$result['Body'];
					
					$imageParts = getimagesizefromstring($imageData);
					$cxSrc      = $imageParts[0];
					$cySrc      = $imageParts[1];
					
					if ($cxDest == $cxSrc && $cyDest == $cySrc)
					{
						$s3client->copyObject(array(
							'Bucket'     => $S3_BUCKET,
							'Key'        => $cacheFile,
							'CopySource' => urlencode("{$S3_BUCKET}/{$originalFile}")
						));
					}
					else
					{
						$image = imagecreatefromstring($imageData);
						$destImage = ResampleImage($image, $imageParts[2], $cxDest, $cyDest);
						
						// See http://stackoverflow.com/questions/1206884/php-gd-how-to-get-imagedata-as-binary-string
						ob_start();
						switch ($imageParts[2])
						{
							case IMAGETYPE_GIF:
								imagegif($destImage);
								break;
							case IMAGETYPE_JPEG:
								imagejpeg($destImage);
								break;
							case IMAGETYPE_PNG:
								imagepng($destImage);
								break;
							default:
								NotFound(sprintf('Unsupported image type: %d', $imageParts[2]));
						}
						$destImageData = ob_get_contents();
						ob_end_clean();
						
						$result = $s3client->putObject(array(
							'Bucket'      => $S3_BUCKET,
							'Key'         => $cacheFile,
							'Body'        => $destImageData,
							'ContentType' => image_type_to_mime_type($imageParts[2])
						));
					}
					
					$s3client->waitUntil('ObjectExists', array(
						'Bucket' => $S3_BUCKET,
						'Key'    => $cacheFile
					));					
				}
				catch (\Aws\S3\Exception\S3Exception $e)
				{
					NotFound($e->getMessage() . ' :' . $originalFile);
				}
			}
			
			header("Location: " . $cacheFileUrl, true, 301);
			exit();							
		}
		else
		{
			header("Location: " . $originalFileUrl, true, 301);
			exit();			
		}
	}
	else
	{
		$originalFile = sprintf("%s/%s/%s/originals/%s%s", $FILES_ROOT, $AccountName, $Folder, $dirCharsFolder, $File);
		
		if ($fileExtension == "pdf")
		{
			if (!file_exists($originalFile))
				NotFound('File not found');
						
			header("Content-type: application/pdf");
			readfile($originalFile);
			exit();
		}
		if ($fileExtension == "swf")
		{
			if (!file_exists($originalFile))
				NotFound('File not found');
						
			header("Content-type: application/x-shockwave-flash");
			readfile($originalFile);
			exit();
		}
				
		if (preg_match('/\-(\d+)x(\d+)/i', $fileParts['filename'], $matches))
		{
			$cxDest         = intval($matches[1]);
			$cyDest         = intval($matches[2]);
			$fileName       = substr($fileParts['filename'], 0, strlen($fileParts['filename']) - strlen($matches[0]));
			$dirCharsFolder = $FILES_DIR_CHARS > 0 ? GetFolderName($fileName) . "/" : "";
			
			$originalFile = sprintf("%s/%s/%s/originals/%s%s.%s", $FILES_ROOT, $AccountName, $Folder, $dirCharsFolder, $fileName, $fileExtension);
			if (!file_exists($originalFile))
			{
				$fileName      = "default";
				$fileExtension = "gif";
				$dirCharsFolder = $FILES_DIR_CHARS > 0 ? GetFolderName($fileName) . "/" : "";
				$originalFile = sprintf("%s/%s/%s/originals/%s%s.%s", $FILES_ROOT, $AccountName, $Folder, $dirCharsFolder, $fileName, $fileExtension);
				
				if (!file_exists($originalFile))
					NotFound('File not found');
			}
				
			$imageParts = getimagesize($originalFile);
			$cxSrc      = $imageParts[0];
			$cySrc      = $imageParts[1];
			
			if ($cxDest != $cxSrc || $cyDest != $cySrc)
			{
				$dirCharsFolder = $FILES_DIR_CHARS > 0 ? "/" . GetFolderName($fileName) : "";
				$cacheFolder = sprintf("%s/%s/%s/cache/%dx%d%s", $FILES_ROOT, $AccountName, $Folder, $cxDest, $cyDest, $dirCharsFolder);
				$cacheFile   = sprintf("%s/%s.%s", $cacheFolder, $fileName, $fileExtension);
				if (!file_exists($cacheFolder))
					mkdir($cacheFolder, 0777, true);
					
				if (!file_exists($cacheFile))
				{
					switch ($imageParts[2])
					{
						case IMAGETYPE_GIF:
							$image     = imagecreatefromgif($originalFile);
							$destImage = ResampleImage($image, $imageParts[2], $cxDest, $cyDest);
							imagegif($destImage, $cacheFile);
							break;
						case IMAGETYPE_JPEG:
							$image     = imagecreatefromjpeg($originalFile);
							$destImage = ResampleImage($image, $imageParts[2], $cxDest, $cyDest);
							imagejpeg($destImage, $cacheFile);
							break;
						case IMAGETYPE_PNG:
							$image     = imagecreatefrompng($originalFile);
							$destImage = ResampleImage($image, $imageParts[2], $cxDest, $cyDest);
							imagepng($destImage, $cacheFile);
							break;
						default:
							NotFound('Unsupported image type');
					}
					
					imagedestroy($destImage);
				}
				
				header(sprintf('Content-type: %s', $imageParts['mime']));
				readfile($cacheFile);
				exit();
			}
		}

		$imageParts = getimagesize($originalFile);
		header(sprintf('Content-type: %s', $imageParts['mime']));
		readfile($originalFile);
		exit();
	}
	
function GetFolderName($fileParts)
{
	global $FILES_DIR_CHARS;
	
	$fileName = is_array($fileParts) ? $fileParts["filename"] : $fileParts;
	$temp     = str_pad(str_replace(" ", "_", $fileName), $FILES_DIR_CHARS, "_", STR_PAD_LEFT);
	
	return strtolower(substr($temp, -$FILES_DIR_CHARS));
}
	
function NotFound($message)
{
	header("Status: 404");
	header("Content-Type: text/html" );
	printf("<HTML><HEAD></HEAD><BODY><H1>%s</H1></BODY></HTML>\r\n", $message);	
	exit();
}	

function ResampleImage(&$srcImage, $imageType, $destWidth, $destHeight)
{
	$srcWidth  = imagesx($srcImage);
	$srcHeight = imagesy($srcImage);
	
	$ratioSrc  = $srcWidth  / $srcHeight;
	$ratioDest = $destWidth / $destHeight;
	
	if($ratioDest > $ratioSrc)
		$destWidth = $destHeight * $ratioSrc;
	else
		$destHeight = $destWidth / $ratioSrc;
	
	$destImage = ImageCreateTrueColor($destWidth, $destHeight);
	
	if ($imageType == IMAGETYPE_PNG) 
	{
		imagealphablending($destImage, false);
		$colorTransparent = imagecolorallocatealpha($destImage, 0, 0, 0, 127);
		imagefill($destImage, 0, 0, $colorTransparent);
		imagesavealpha($destImage, true);
	} 
	else if ($imageType == IMAGETYPE_GIF) 
	{
		$trnprt_indx = imagecolortransparent($srcImage);
		if ($trnprt_indx >= 0) 
		{
			$trnprt_color = imagecolorsforindex($srcImage, $trnprt_indx);
			$trnprt_indx  = imagecolorallocate($destImage, $trnprt_color['red'], $trnprt_color['green'], $trnprt_color['blue']);
			imagefill($destImage, 0, 0, $trnprt_indx);
			imagecolortransparent($destImage, $trnprt_indx);
		}
	}
	
	fastimagecopyresampled($destImage, $srcImage, $imageType, 0,0,0,0, $destWidth, $destHeight, $srcWidth, $srcHeight); 
	return $destImage;
}

function fastimagecopyresampled (&$dst_image, $src_image, $imageType, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h, $quality = 3) 
{
	// Plug-and-Play fastimagecopyresampled function replaces much slower imagecopyresampled.
	// Just include this function and change all "imagecopyresampled" references to "fastimagecopyresampled".
	// Typically from 30 to 60 times faster when reducing high resolution images down to thumbnail size using the default quality setting.
	// Author: Tim Eckel - Date: 09/07/07 - Version: 1.1 - Project: FreeRingers.net - Freely distributable - These comments must remain.
	//
	// Optional "quality" parameter (defaults is 3). Fractional values are allowed, for example 1.5. Must be greater than zero.
	// Between 0 and 1 = Fast, but mosaic results, closer to 0 increases the mosaic effect.
	// 1 = Up to 350 times faster. Poor results, looks very similar to imagecopyresized.
	// 2 = Up to 95 times faster.  Images appear a little sharp, some prefer this over a quality of 3.
	// 3 = Up to 60 times faster.  Will give high quality smooth results very close to imagecopyresampled, just faster.
	// 4 = Up to 25 times faster.  Almost identical to imagecopyresampled for most images.
	// 5 = No speedup. Just uses imagecopyresampled, no advantage over imagecopyresampled.

	if (empty($src_image) || empty($dst_image) || $quality <= 0) 
	{ 
		return false; 
	}
	if 
	(
		($imageType != IMAGETYPE_PNG && $imageType != IMAGETYPE_GIF) &&
		($quality < 5 && (($dst_w * $quality) < $src_w || ($dst_h * $quality) < $src_h))
	) 
	{
		$temp = imagecreatetruecolor ($dst_w * $quality + 1, $dst_h * $quality + 1);
		imagecopyresized ($temp, $src_image, 0, 0, $src_x, $src_y, $dst_w * $quality + 1, $dst_h * $quality + 1, $src_w, $src_h);
		imagecopyresampled ($dst_image, $temp, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $dst_w * $quality, $dst_h * $quality);
		imagedestroy ($temp);
	} 
	else 
	{
		imagecopyresampled ($dst_image, $src_image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h);
	}
	
	return true;
}

?>
