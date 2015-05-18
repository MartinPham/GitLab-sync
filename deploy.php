<?php

/*
	GitLab Sync (c) Martin Pham

	http://martinpham.co

	File: deploy.php
	Version: 1.0.0
	Description: Local file sync script for GitLab projects


	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.
*/


/*
	This script has two modes of operation detailed below.
	
	The two modes of operation are complementary and are designed to be used
	with projects that are configured to be kept in sync through this script. 
	
	The usual way of getting the project prepared is to make an initial full 
	sync of the	project files (through operation mode 2) and then to configure
	the POST service hook in GitLab and let the script synchronize changes 
	as they happen (through operation mode 1).
	
	
	1. Full synchronization
	
	This mode can be enabled by specifying the "setup" GET parameter in the URL
	in which case the script will get the full repository from GitLab and
	deploy it locally. This is done by getting a zip archive of the project,
	extracting it locally and copying its contents over to the specified
	project location, on the local file-system.
	
	This operation mode does not necessarily need a POST service hook to be 
	defined in GitLab for the project and is generally suited for initial 
	set-up of projects that will be kept in sync with this script. 
	
	
	2. Commit synchronization
	
	TODO...
	
 */


ini_set('display_errors','On'); 
ini_set('error_reporting', E_ALL);
require_once( 'config.php' );

// For 4.3.0 <= PHP <= 5.4.0
if (!function_exists('http_response_code'))
{
    function http_response_code($newcode = NULL)
    {
        static $code = 200;
        if($newcode !== NULL)
        {
            header('X-PHP-Response-Code: '.$newcode, true, $newcode);
            if(!headers_sent())
                $code = $newcode;
        }       
        return $code;
    }
}

if (!isset($key)) {
	if(isset($_GET['key'])) {
		$key = strip_tags(stripslashes(urlencode($_GET['key'])));

	} else $key = '';
}

if(isset($_GET['setup']) && !empty($_GET['setup'])) {
	# full synchronization
	$repo = strip_tags(stripslashes(urldecode($_GET['setup'])));
	syncFull($key, $repo);
	
} else if(isset($_GET['retry'])) {
	# retry failed synchronizations
	syncChanges($key, true);
	
} else {
	# commit synchronization
	syncChanges($key);
}


/**
 * Gets the full content of the repository and stores it locally.
 * See explanation at the top of the file for details.
 */
function syncFull($key, $repository) {
	global $CONFIG, $DEPLOY, $DEPLOY_BRANCH;
	$shouldClean = isset($_GET['clean']) && $_GET['clean'] == 1;

	// check authentication key if authentication is required
	if ( $shouldClean && $CONFIG[ 'deployAuthKey' ] == '' ) {
		// when cleaning, the auth key is mandatory, regardless of requireAuthentication flag
		http_response_code(403);
		echo " # Cannot clean right now. A non-empty deploy auth key must be defined for cleaning.";
		return false;
	} else if ( ($CONFIG[ 'requireAuthentication' ] || $shouldClean) && $CONFIG[ 'deployAuthKey' ] != $key ) {
		http_response_code(401);
		echo " # Unauthorized." . ($shouldClean && empty($key) ? " The deploy auth key must be provided when cleaning." : "");
		return false;
	}
	
	echo "<pre>\nGitLab Sync - Full Deploy\n============================\n";
	
	// determine the destination of the deployment
	if( array_key_exists($repository, $DEPLOY) ) {
		$deployLocation = $DEPLOY[ $repository ] . (substr($DEPLOY[ $repository ], -1) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR);
	} else {
		echo " # Unknown repository: $repository!";
		return false;
	}
	
	// determine from which branch to get the data
	if( isset($DEPLOY_BRANCH) && array_key_exists($repository, $DEPLOY_BRANCH) ) {
		$deployBranch = $DEPLOY_BRANCH[ $repository ];
	} else {
		// use the default branch
		$deployBranch = $CONFIG['deployBranch'];
	}

	// build URL to get the full archive
	$baseUrl = 'https://gitlab.com/';
	$repoUrl = (!empty($_GET['team']) ? $_GET['team'] : $CONFIG['apiUser']) . "/$repository/";
	$branchUrl = 'repository/archive.zip?ref=' . $deployBranch . '';
	
	// store the zip file temporary
	$zipFile = 'full-' . time() . '-' . rand(0, 100);
	$zipLocation = dirname(__FILE__) . '/' . $CONFIG['commitsFolder'] . (substr($CONFIG['commitsFolder'], -1) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR);

	// get the archive
	loginfo(" * Fetching archive from $baseUrl$repoUrl$branchUrl\n");
	$result = getFileContents($baseUrl . $repoUrl . $branchUrl, $zipLocation . $zipFile);

	loginfo(" * Downloaded to " . $zipLocation . $zipFile . " (".filesize($zipLocation . $zipFile)." byte(s))\n");
	
	// extract contents
	loginfo(" * Extracting archive to $zipLocation\n");
	$zip = new ZipArchive;
	if( $zip->open($zipLocation . $zipFile) === true ) {
		$zip->extractTo($zipLocation);
		$stat = $zip->statIndex(0); 
		$folder = $stat['name'];
		$zip->close();
	} else {
		echo " # Unable to extract files. Is the repository name correct?";
		unlink($zipLocation . $zipFile);
		return false;
	}
	
	// validate extracted content
	if( empty($folder) || !is_dir( $zipLocation . $folder ) ) {
		echo " # Unable to find the extracted files in $zipLocation\n";
		unlink($zipLocation . $zipFile);
		return false;
	}
	
	// delete the old files, if instructed to do so
	if( $shouldClean ) {
		loginfo(" * Deleting old content from $deployLocation\n");
		if( deltree($deployLocation) === false ) {
			echo " # Unable to completely remove the old files from $deployLocation. Process will continue anyway!\n";
		}
	}
	
	// copy the contents over
	loginfo(" * Copying new content to $deployLocation\n");
	if( cptree($zipLocation . $folder, $deployLocation) == false ) {
		echo " # Unable to deploy the extracted files to $deployLocation. Deployment is incomplete!\n";
		deltree($zipLocation . $folder, true);
		unlink($zipLocation . $zipFile);
		return false;
	}
	
	// clean up
	loginfo(" * Cleaning up temporary files and folders\n");
	deltree($zipLocation . $folder, true);
	unlink($zipLocation . $zipFile);
	
	echo "\nFinished deploying $repository.\n</pre>";
}


/**
 * Synchronizes changes from the commit files.
 * See explanation at the top of the file for details.
 */
function syncChanges($key, $retry = false) {
	global $CONFIG;
	global $processed;
	global $rmdirs;
	
	// check authentication key if authentication is required
	if ( $CONFIG[ 'requireAuthentication' ] && $CONFIG[ 'deployAuthKey' ] != $key) {
		http_response_code(401);
		echo " # Unauthorized";
		return false;
	}

	echo "<pre>\nGitLab Sync\n==============\n";
	
	$json = file_get_contents('php://input');
	$json = json_decode($json);

	$repository = $json->repository;
	$homepage = $repository->homepage;

	$data = explode("/", $homepage);

	$_GET['team'] = $data[count($data) - 2];
	$repo = $data[count($data)-1];


// file_put_contents(dirname(__FILE__) . '/l.txt', $homepage);die;


	syncFull("", $repo);


	
	echo "\nFinished processing commits.\n</pre>";
}



/**
 * Gets token  using CURL
 */
function getPrivateToken() {
	global $CONFIG;
	
	if($CONFIG['apiToken'] != "") return $CONFIG['apiToken'];
	
	echo "Getting token\n";

	// create a new cURL resource
	$ch = curl_init();
	
	// set URL and other appropriate options
	curl_setopt($ch, CURLOPT_URL, 'https://gitlab.com/api/v3/session');
	
	curl_setopt($ch, CURLOPT_HEADER, false);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    

	curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)");
	
	curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC ) ;
	if(!empty($CONFIG['apiUser'])) {
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, 'login='.$CONFIG['apiUser'].'&password='.$CONFIG['apiPassword']);
	}
	// Remove to leave curl choose the best version
	//curl_setopt($ch, CURLOPT_SSLVERSION,3); 
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
	
	// grab URL
	$data = curl_exec($ch);
	



	if(curl_errno($ch) != 0) {
		echo "      ! File transfer error: " . curl_error($ch) . "\n";
		return false;
	}
	

	$jdata = json_decode($data);

	if (!$jdata){
		echo "Invalid JSON: " . $data . "\n";
		return false;
	}

	$token = $jdata->private_token;

	// close cURL resource, and free up system resources
	curl_close($ch);
	


	return $token;
}

/**
 * Gets a remote file contents using CURL
 */
function getFileContents($url, $writeToFile = false) {
	global $CONFIG;
	
	// create a new cURL resource
	$ch = curl_init();
	
	// get token
	$token = getPrivateToken();
	if(!$token){
		echo "Cannot get token\n";
		return;
	}


	echo "Got Token\n";

	// set URL and other appropriate options
	curl_setopt($ch, CURLOPT_URL, $url . '&private_token=' . $token);
	
	curl_setopt($ch, CURLOPT_HEADER, false);

    if ($writeToFile) {
        $out = fopen($writeToFile, "wb");
        if ($out == FALSE) {
            throw new Exception("Could not open file `$writeToFile` for writing");
        }
        curl_setopt($ch, CURLOPT_FILE, $out);
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }

	curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)");
	
	// Remove to leave curl choose the best version
	//curl_setopt($ch, CURLOPT_SSLVERSION,3); 
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

	// grab URL
	$data = curl_exec($ch);


	if(curl_errno($ch) != 0) {
		echo "      ! File transfer error: " . curl_error($ch) . "\n";
	}
	
	// close cURL resource, and free up system resources
	curl_close($ch);
	
	return $data;
}


/**
 * Copies the directory contents, recursively, to the specified location
 */
function cptree($dir, $dst) {
	if (!file_exists($dst)) if(!mkdir($dst, 0755, true)) return false;
	if (!is_dir($dir) || is_link($dir)) return copy($dir, $dst); // should not happen
	$files = array_diff(scandir($dir), array('.','..'));
	$sep = (substr($dir, -1) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR);
	$dsp = (substr($dst, -1) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR);
	foreach ($files as $file) {
		(is_dir("$dir$sep$file")) ? cptree("$dir$sep$file", "$dst$dsp$file") : copy("$dir$sep$file", "$dst$dsp$file");
	}
	return true;
}


/**
 * Deletes a directory recursively, no matter whether it is empty or not
 */
function deltree($dir, $deleteParent = false) {
	if (!file_exists($dir)) return false;
	if (!is_dir($dir) || is_link($dir)) return unlink($dir);
	// prevent deletion of current directory
	$cdir = realpath($dir);
	$adir = dirname(__FILE__);
	$cdir = $cdir . (substr($cdir, -1) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR);
	$adir = $adir . (substr($adir, -1) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR);
	if( $cdir == $adir ) {
		loginfo(" * Contents of '" . basename($adir) . "' folder will not be cleaned up.\n");
		return true;
	}
	// process contents of this dir
	$files = array_diff(scandir($dir), array('.','..'));
	$sep = (substr($dir, -1) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR);
	foreach ($files as $file) {
		(is_dir("$dir$sep$file")) ? deltree("$dir$sep$file", true) : unlink("$dir$sep$file");
	}

	if($deleteParent) {
		return rmdir($dir);
	} else {
		return true;
	}
}


/**
 * Outputs some information to the screen if verbose mode is enabled
 */
function loginfo($message) {
	global $CONFIG;
	if( $CONFIG['verbose'] ) {
		echo $message;
		flush();
	}
}
