<?php
if (!$users->isLoggedIn())
	$page->show403();

if (empty($_GET["id"]))
	$page->show404();

require_once nZEDb_LIB . 'sabnzbd.php';
$sab = new SABnzbd($page);

if (empty($sab->url))
	$page->show404();

if (empty($sab->apikey))
	$page->show404();

$guid = $_GET["id"];

$sab->sendToSab($guid);

?>
