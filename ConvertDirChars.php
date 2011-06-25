<?php

	// Copyright (C) 2008 Teztech, Inc.
	// All Rights Reserved
	
	require_once("www/config.php");
	
	if ($argc <= 2)
		Usage();

	$command      = $argv[1];	
	$oldDirChars  = $argc >= 3 ? $argv[2] : 0;
	$newDirChars  = $argc >= 4 ? $argv[3] : 2;
	$filesRoot    = $argc >= 5 ? $argv[4] : "files";
	
	if (($command != "copy" && $command != "delete" && $command != "testCopy" && $command != "testDelete") || 
		!is_numeric($oldDirChars) || !is_numeric($newDirChars) || !file_exists($filesRoot))
		Usage();
	
	if ($hostHandle = @opendir($filesRoot)) 
	{
		while (false !== ($hostFolder = readdir($hostHandle ))) 
		{
			if ($hostFolder != "." && $hostFolder != ".." && $hostFolder != ".svn") 
			{
				echo "Processing folders for host '$hostFolder'\n";
				
				$folderFolder = sprintf("%s/%s", $filesRoot, $hostFolder);
				
				if ($folderHandle = @opendir($folderFolder)) 
				{
					while (false !== ($folder = readdir($folderHandle))) 
					{
						if ($folder != "." && $folder != ".." && $folder != ".svn") 
						{
							echo "++> Processing cache and originals for folder '$folder'\n";
							
							$cacheFolders = sprintf("%s/%s/%s/cache", $filesRoot, $hostFolder, $folder);
							
							if ($cacheHandle = @opendir($cacheFolders )) 
							{
								while (false !== ($cacheFolder = readdir($cacheHandle))) 
								{
									if ($cacheFolder != "." && $cacheFolder != ".." && $cacheFolder != ".svn") 
									{
										$srcFolders = sprintf("%s/%s", $cacheFolders, $cacheFolder);
										echo "++++> Processing cache folder '$srcFolders'\n";
										
										if ($oldDirChars > 0)
											ConvertOldDirFiles($srcFolders);
										else
											ConvertFiles($srcFolders, $srcFolders);
									}
								}
								closedir($cacheHandle);
							}
							
							$originalsFolders = sprintf("%s/%s/%s/originals", $filesRoot, $hostFolder, $folder);
							echo "++++> Processing originals folder '$originalsFolders'\n";
							
							if ($oldDirChars > 0)
								ConvertOldDirFiles($originalsFolders);
							else
								ConvertFiles($originalsFolders, $originalsFolders);
						}
					}
				}
				closedir($folderHandle);
				
				echo "\n";
			}
		}
		closedir($hostHandle);
	}
	
	exit();

function Usage()
{
	echo "USAGE: ConvertDirChars copy|testCopy|delete|testDelete oldDirChars newDirChars filesRootDir\n";
	exit();
}
	
function ConvertOldDirFiles($cacheFolder)
{
	global $command;
	global $oldDirChars;
	
	if ($oldDirHandle = @opendir($cacheFolder)) 
	{
		while (false !== ($oldDirFolder = readdir($oldDirHandle))) 
		{
			if ($oldDirFolder != "." && $oldDirFolder != ".." && $oldDirFolder != ".svn" && strlen($oldDirFolder) == $oldDirChars) 
			{
				$srcFolder = sprintf("%s/%s", $cacheFolder, $oldDirFolder);
				echo "++++++> Processing old dir folder '$srcFolder'\n";
				ConvertFiles($srcFolder, $cacheFolder);
				if ($command == "delete")
					rmdir($srcFolder);
			}
		}
		closedir($oldDirHandle);
	}
}
	
function ConvertFiles($filesFolder, $parentFolder)
{
	global $command;
	$newFolders = array();

	if ($filesFolderHandle = @opendir($filesFolder)) 
	{
		while (false !== ($fileName = readdir($filesFolderHandle))) 
		{
			$newFolder = GetFolderName(pathinfo($fileName));
			$srcPath   = sprintf("%s/%s", $filesFolder, $fileName);
			$destPath  = sprintf("%s/%s/%s", $parentFolder, $newFolder, $fileName);
			
			if (is_file($srcPath)) 
			{
				if ($command == "delete" || $command == "testDelete")
				{
					echo "--------> Deleting '$srcPath''\n";
					if ($command == "delete")
						unlink($srcPath);
				}
				else
				{
					if (!in_array($newFolder, $newFolders))
					{
						$destFolder = sprintf("%s/%s", $parentFolder, $newFolder);
						echo "--------> Creating folder $destFolder\n";
						if ($command == "copy")
							mkdir($destFolder, 0777, true);
						array_push($newFolders, $newFolder);
					}
				
					echo "--------> Copying '$srcPath' to '$destPath'\n";
					if ($command == "copy")
						copy($srcPath, $destPath);
				}
			}
		}
		closedir($filesFolderHandle);
	}
}

function GetFolderName($fileParts)
{
	global $newDirChars;
	
	$temp = str_pad(str_replace(" ", "_", $fileParts["filename"]), $newDirChars, "_", STR_PAD_LEFT);
	
	return strtolower(substr($temp, -$newDirChars));
}

?>
