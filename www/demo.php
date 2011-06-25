<?php

	// Copyright (C) 2008 Teztech, Inc.
	// All Rights Reserved

	require_once("config.php");
	
	$HostName = $_SERVER['HTTP_HOST'];
	$Folder   = isset($_REQUEST['Folder']) ? $_REQUEST['Folder'] : 'folder1';
	
	$ErrorMessage = '';
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>
Blob server demo
</title>
</head>
<body>
<form name="Demo" method="POST" action="demo.php">

	<?php if($ErrorMessage): ?>
	<p><font size="5" color="#008000"><?php echo $ErrorMessage; ?></font></p>
	<?php endif; ?>
	
	<b>Folder Name:</b> <input name="Folder" value='<?php echo $Folder ?>'></input>
	
	<?php if ($Folder != ''): ?>
	
	<table border="1" cellpadding="0" cellspacing="0">
	<?php 
		$dirCharsFoldersPath   = $FILES_DIR_CHARS > 0 ? sprintf("%s/%s/%s/originals", $FILES_ROOT, $HostName, $Folder) : NULL;
		$dirCharsFoldersHandle = $FILES_DIR_CHARS > 0 ? opendir($dirCharsFoldersPath) : FALSE;
		
		while (!$dirCharsFoldersHandle || false !== ($dirCharsFolder = readdir($dirCharsFoldersHandle)))
		{
			if ($dirCharsFoldersHandle && ($dirCharsFolder == "." || $dirCharsFolder == ".."))
				continue;
		
			$dirCharsFolder  = $FILES_DIR_CHARS > 0 ? "/" . $dirCharsFolder : "";	
			$originalsFolder = sprintf("%s/%s/%s/originals%s", $FILES_ROOT, $HostName, $Folder, $dirCharsFolder);
		
			if ($handle = opendir($originalsFolder)) 
			{
				while (false !== ($imageEntry = readdir($handle))) 
				{
					if($imageEntry == "." || $imageEntry == "..")
						continue;
						
					$fileParts = pathinfo($imageEntry);
					$fileName  = sprintf("%s-100x100.%s", $fileParts['filename'], $fileParts['extension']);
	?>
	<tr>
		<td><img src='<?php printf("/get.php?Folder=%s&File=%s", $Folder, $fileName); ?>' /></td>
		<td><?php echo $imageEntry; ?></td>
		<td><a href='<?php printf("/post.php?Password=password&Action=Delete&Folder=%s&File=%s", $Folder, $imageEntry); ?>'>Delete</a></td>
	</tr>
	<?php
				}
				closedir($handle);
			}
			
			if (!$dirCharsFoldersHandle)
				break;
		}
		
		if($dirCharsFoldersHandle)
			closedir($dirCharsFoldersHandle);
	?>
	</table>
	
	<?php endif; ?>
	
	<br /><br />
	<INPUT TYPE=BUTTON VALUE="Add Image" NAME="AddImage" OnClick="AddImage_OnClick()">
	
    <div style="display: none">
        <object id="dcuctl" classid="clsid:652FE09F-3289-414A-B1D7-2E661FA6119B" 
        	codebase='<?php echo "http://$HostName/controls/ImageUploadCtrl.cab#version=1,0,0,1"; ?>'
            width="0" height="0" visible="false">
        </object>
    </div>
    
</body>

<SCRIPT LANGUAGE="JavaScript">
<!--
function AddImage_OnClick()
{
	Demo.dcuctl.DesignWidth = 255;
	Demo.dcuctl.DesignHeight = 160;
	Demo.dcuctl.CameraType = 4; // 4 = Computer Folder
	Demo.dcuctl.UploadImageURL = '<?php echo "http://$HostName/post.php?Password=password&Action=Update&Folder=$Folder&File=test.jpg"; ?>';
	Demo.dcuctl.GetImage(); // This call blocks until the Get Image dialog is closed
	Demo.submit();          // The call to submit causes the page to be refreshed
}
-->
</SCRIPT>

</html>
