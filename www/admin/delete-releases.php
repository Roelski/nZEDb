<?php
require_once './config.php';

$page = new AdminPage();
$page->title = "Delete Releases";
$page->smarty->assign(array('error', 'done'), '');

// Set the current action.
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'view';

switch($action) {
	case 'submit':
		$values = parseResponse($_POST);
		if ($values === false) {
			$page->smarty->assign('error', 'Your search criteria is wrong.');
		} else {
			$RR = new ReleaseRemover(true);
			$succeeded = $RR->removeByCriteria($values);
			if (substr($succeeded, 0, 7) === 'Success') {
				$page->smarty->assign('done', $succeeded);
			} else {
				$page->smarty->assign('error', $succeeded);
			}
		}

		$release = array();
		foreach ($_POST as $key => $value) {
			$release[$key] = $value;
		}
		$page->smarty->assign('release', $release);

		break;

	case 'view':
	default:
		$release = array(
			'name'         => '',
			'searchname'   => '',
			'fromname'     => '',
			'groupname'    => '',
			'relsize'      => '',
			'adddate'      => '',
			'postdate'     => '',
			'relguid'      => '',
			'nametypesel'  => '1',
			'snametypesel' => '1',
			'fnametypesel' => '1',
			'gnametypesel' => '1',
			'sizetypesel'  => '0',
			'adatetypesel' => '0',
			'pdatetypesel' => '0'
		);
		$page->smarty->assign('release', $release);
		break;
}

$page->smarty->assign('type1_ids',   array(0, 1));
$page->smarty->assign('type2_ids',   array(0, 1, 2));
$page->smarty->assign('type1_names', array('Like', 'Equals'));
$page->smarty->assign('type2_names', array('Bigger', 'Smaller', 'Equals'));
$page->smarty->assign('type3_names', array('Bigger', 'Smaller'));

$page->content = $page->smarty->fetch('delete-releases.tpl');
$page->render();

function parseResponse($response) {
	$options = array();
	foreach ($response as $key => $value) {
		switch ($key) {
			case 'name':
			case 'searchname':
			case 'fromname':
			case 'groupname':
			case 'adddate':
			case 'postdate':
				$options[$key]['value'] = $value;
				break;
			case 'relsize':
				$options['size']['value'] = $value;
				break;
			case 'relguid':
				$options['guid']['value'] = $value;
				$options['guid']['type'] = 'equals';
				break;
			case 'nametypesel':
				switch ($value) {
					case '';
						break;
					case '0':
						$options['name']['type'] = 'like';
						break;
					case '1':
						$options['name']['type'] = 'equals';
						break;
				}
				break;
			case 'snametypesel':
				switch ($value) {
					case '';
						break;
					case '0':
						$options['searchname']['type'] = 'like';
						break;
					case '1':
						$options['searchname']['type'] = 'equals';
						break;
				}
				break;
			case 'fnametypesel':
				switch ($value) {
					case '';
						break;
					case '0':
						$options['fromname']['type'] = 'like';
						break;
					case '1':
						$options['fromname']['type'] = 'equals';
						break;
				}
				break;
			case 'gnametypesel':
				switch ($value) {
					case '';
						break;
					case '0':
						$options['groupname']['type'] = 'like';
						break;
					case '1':
						$options['groupname']['type'] = 'equals';
						break;
				}
				break;
			case 'sizetypesel':
				switch ($value) {
					case '';
						break;
					case '0':
						$options['size']['type'] = 'bigger';
						break;
					case '1':
						$options['size']['type'] = 'smaller';
						break;
					case '2':
						$options['size']['type'] = 'equals';
						break;
				}
				break;
			case 'adatetypesel':
				switch ($value) {
					case '';
						break;
					case '0':
						$options['adddate']['type'] = 'bigger';
						break;
					case '1':
						$options['adddate']['type'] = 'smaller';
						break;
				}
				break;
			case 'pdatetypesel':
				switch ($value) {
					case '';
						break;
					case '0':
						$options['postdate']['type'] = 'bigger';
						break;
					case '1':
						$options['postdate']['type'] = 'smaller';
						break;
				}
				break;
		}
	}
	$retVal = array();
	foreach ($options as $key => $value) {
		if ($value['value'] === '') {
			continue;
		}
		$retVal[] = $key . '=' . $value['type'] . '=' . $value['value'];

	}
	if (count($retVal) === 0) {
		return false;
	}
	return $retVal;
}