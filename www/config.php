<?php

	// Copyright (C) 2008 Teztech, Inc.
	// All Rights Reserved

	$DB_SERVER   = "localhost";
	$DB_DATABASE = "blobs";
	$DB_USERNAME = "blobs";
	$DB_PASSWORD = "blobx2323pwd";
	
	$FILES_ROOT      = "../files";
	$FILES_DIR_CHARS = 2;
	$EXTENSIONS      = "jpg,jpeg,gif,png,bmp,pdf,swf"; // Don't use any spaces
	
	$MySQL = @mysql_connect ($DB_SERVER, $DB_USERNAME, $DB_PASSWORD); // or die ('ERROR: Sorry cannot connect to database.');
	@mysql_select_db($DB_DATABASE); // or die("ERROR: Sorry cannot select database.");
	
?>
