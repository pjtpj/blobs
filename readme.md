blobs
=====

Simple blob storage server. Written in PHP for extreme portability. Works on Windows and Linux.


Installation
------------

Blobs requires a Mysql database to hold account hostnames and login information. Blobs expects to be
running on a root level virtual host. URL rewriting is typically used to map pretty URLs to get.php.
To support AWS S3, PHP Composer must be installed and "composer install" must be run from the blobs
home folder.
