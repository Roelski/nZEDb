<?php
/* This script is designed to gather all show data from anidb and add it to the anidb table for nZEDb, as part of this process we need the number of PI queries that can be executed max and whether or not we want debuging the first argument if unset will try to do the entire list (a good way to get banned), the second option can be blank or true for debugging.
* IF you are using this script then then you also want to edit anidb.php in www/lib and locate "604800" and replace it with 1204400, this will make sure it never tries to connect to anidb as this will fail
*/

require dirname(__FILE__) . '/../../../www/config.php';

use nzedb\db\DB;
use nzedb\utility;

class AniDBstandAlone {

	const CLIENTVER = 1;
	function __construct($echooutput=false) {
		$s = new Sites();
		$this->site = $s->get();
		$this->aniqty = (!empty($this->site->maxanidbprocessed)) ? $this->site->maxanidbprocessed : 100;
		$this->echooutput = $echooutput;
		$this->imgSavePath = nZEDb_COVERS . 'anime' . DS;
		$this->APIKEY = $this->site->anidbkey;
		$this->db = new DB();
		$this->c = new ColorCLI();
		}

	// ===== function getanimetitlesUpdate =================================================================
	public function animetitlesUpdate() {

		$db = $this->db;
		$lastUpdate = $db->queryOneRow('SELECT max(unixtime) as utime FROM animetitles');
		if (isset($lastUpdate['utime']) && (time() - $lastUpdate['utime']) < 604800) {
			if ($this->echooutput) {
				echo "\n";
				echo $this->c->info("Last update occurred less than 7 days ago, skipping full dat file update.\n\n");
				}
			return;
			}

		if ($this->echooutput) {
			echo $this->c->header("Updating animetitles by grabbing full dat AniDB dump.\n\n");
			}
		$zh = gzopen('http://anidb.net/api/anime-titles.dat.gz', 'r');
		preg_match_all('/(\d+)\|\d\|.+\|(.+)/', gzread($zh, '10000000'), $animetitles);

		if (!$animetitles) {
			return false;
			}

		$db->queryExec('DELETE FROM animetitles WHERE anidbid IS NOT NULL');
		if ($this->echooutput) {
			echo $this->c->header("Total of ".count($animetitles[1])." titles to add\n\n");
			}
		for ($loop = 0; $loop < count($animetitles[1]); $loop++) {
			$db->queryInsert(sprintf('INSERT IGNORE INTO animetitles (anidbid, title, unixtime) VALUES (%d, %s, %d)',
			$animetitles[1][$loop], $db->escapeString(html_entity_decode($animetitles[2][$loop], ENT_QUOTES, 'UTF-8')), time()
			));
			}
		if ($loop % 2500 == 0 && $this->echooutput) {
			echo $this->c->header("Completed Processing " . $loop . " titles.\n\n");
			}
		gzclose($zh);
		if ($this->echooutput) {
			echo $this->c->header("Completed animetitles update.\n\n");
			}
		}

	// ===== new getAniDBInfo ==============================================================================
	public function getAniDBInfo($exitcount) {

		// Declare and set main variables
		$db = $this->db;
		$ri = new ReleaseImage();
		$apicount = 0;

		$this->c->doEcho($this->c->header("Start getAniDBInfo at " . date('D M d, Y G:i a')));

		$notinani = sprintf("SELECT animetitles.anidbid FROM animetitles
			INNER JOIN anidb ON animetitles.anidbid = anidb.anidbid"
			);

		// Used for information purposes in main echo
		$animetitles = $db->query('SELECT DISTINCT anidbid FROM animetitles');
		$anidbtitles = $db->query('SELECT DISTINCT anidbid FROM anidb');
		$anidbtitleslang = $db->query('SELECT DISTINCT anidbid FROM anidb_titles');
		$anidbjointitles = $db->query(sprintf("SELECT * FROM animetitles
					INNER JOIN anidb ON animetitles.anidbid = anidb.anidbid"
					));
		$anidbmissingtitles = $db->query(sprintf("SELECT * FROM animetitles
					WHERE anidbid NOT IN (%s)", $notinani
					));

		// Stage declarations
		$aniremovedstage0 = $db->query(sprintf("SELECT anidbid FROM anidb WHERE anidbid NOT IN (%s)", $notinani));
		$animissstage1 = $db->query(sprintf("SELECT DISTINCT anidbid FROM animetitles WHERE anidbid NOT IN (%s)", $notinani));
		$anirunnstage2 = $db->query('SELECT anidbid FROM anidb WHERE (startdate < CURDATE() AND (enddate > CURDATE() OR enddate IS NULL)) AND (unixtime < UNIX_TIMESTAMP(NOW()- INTERVAL 7 DAY)) ORDER BY unixtime');
		$anioldstage3 = $db->query('SELECT anidbid FROM anidb WHERE (unixtime < UNIX_TIMESTAMP(NOW()- INTERVAL 90 DAY)) ORDER BY unixtime');
		echo  $this->c->header("Total of " . count($animetitles) . " distinct titles present in animetitles.\n" .
					"Total of " . count($anidbtitles) . " distinct titles present in anidb.\n" .
					"Total of " . count($anidbtitleslang) . " distinct titles present in anidb_titles.\n" .
					"Total of " . count($anidbjointitles) . " titles in both anidb and animetitles.\n" .
					"Total of " . count($anidbmissingtitles) . " missing titles in anidb table.\n" .
					"Total of " . count($animissstage1) . " missing distinct titles in anidb table.\n" .
					"Total of " . count($aniremovedstage0) . " orphaned anime titles no longer in animetitles to be removed from anidb table.\n" .
					"Total of " . count($anirunnstage2) . " running anime titles in anidb table not updated for 7 days.\n" .
					"Total of " . count($anioldstage3) . " anime titles in anidb table not updated for 90 days.\n");

		if ($this->APIKEY == '') {
			echo $this->c->error("Error: You need an API key from AniDB.net to use this.  Try adding \"nzedb\" in Site Edit.\n");
			return;
		}

		// Show the data for 10 sec before starting
		echo $this->c->info("Starting in 10 sec...\n");
		sleep(10);
		// end debug

		// now do this list:
		// 0) remove removed anidbid's from anidb nnot in animetitles, as these can't be updated
		// 1) insert missing titles until exitcount reached
		// 2) update running shows until exitcount reached
		// 3) update show data older than xxx day's until exitcount reached
		// todo: what to do with anidb.anidbid no longer available in animetitles.anidbid?? ( I have 6 so far)

		// running series:
		// anidb.startdate NULL AND enddate NULL =>> ignore?? (why?) Can only be updated in stage 3!!!!
		// anidb.startdate > CURDATE(); // start date in the future thus it is not in progress as it has not started yet ==> ignore
		// anidb.startdate < CURDATE() AND (enddate IS NULL OR enddate > CURDATE()) => running show without enddate or date in future

		// Begin Stage 0: Remove Orphaned AniDB entries from anidb table if no longer in animetitles table

		$this->c->doEcho($this->c->header("[".date('d-m-Y G:i')."] Stage 0 -> Remove Orphaned AniDB."));

		foreach ($aniremovedstage0 as $value) {
			$anidbid = (int)$value['anidbid'];
			if ($this->echooutput) {
				// Remove AniDB ID from anidb
				echo 'Removing AniDB ID '.$anidbid."\n";
				}
			$this->deleteTitle($anidbid);
			$image_file = $this->imgSavePath . $anidbid;

			// Remove AniDB image if exists
			//if (!file_exists($image_file) {
				//}
			}

		// Begin Stage 1: Insert Missing AniDB entries into AniDB table from animetitles table

		$this->c->doEcho($this->c->header("[".date('d-m-Y G:i')."] Stage 1 -> Insert missing anidbid into anidb table."));

		foreach ($animissstage1 as $value) {
			$anidbid = (int)$value['anidbid'];
			if ($this->echooutput) {
				echo 'Adding AniDB ID ' . $anidbid . "\n";
			}

			// Pull information from AniDB for this ID and increment API counter -- if false (banned) exit
			$AniDBAPIArray = $this->AniDBAPI($anidbid);
			$apicount++;
			if ($AniDBAPIArray['banned']){
				if ($this->echooutput) {
					echo "AniDB Banned, import will fail, please wait 24 hours before retrying\n";
					$this->c->doEcho($this->c->header("Exit getAniDBInfo at " . date('D M d, Y G:i a')));
				}
				return;
			}
			$this->addTitle($AniDBAPIArray);

			// Save the image to covers directory
			if ($AniDBAPIArray['picture']) {
				$ri->saveImage($AniDBAPIArray['anidbid'], 'http://img7.anidb.net/pics/anime/'.$AniDBAPIArray['picture'], $this->imgSavePath);
			}
			// Print total count added
			if ($apicount != 0 && $this->echooutput) {
				echo $this->c->header("Processed " . $apicount . " anidb entries of a total possible of " . $exitcount . " for this session\n");
			}
			// Sleep 4 Minutes for Every 10 Records
			if ($apicount % 10 == 0 && $apicount != 0) {
				$sleeptime = 180 + rand(30,90);
				if ($this->echooutput) {
					$this->c->doEcho($this->c->primary("[".date('d-m-Y G:i')."] Start waitloop for " . $sleeptime . " seconds to prevent banning.\n"));
					}
				sleep($sleeptime);
				}

		// using exitcount if this number of API calls is reached exit
		if ($apicount >= $exitcount) {
			return;
			}
		}

		// Begin Stage 2: Update running series in anidb table -- we only update series already existing in db
		$this->c->doEcho($this->c->header("[".date('d-m-Y G:i')."] Stage 2 -> Update running series."));

		foreach ($anirunnstage2 as $value) {
			$anidbid = (int)$value['anidbid'];

			if ($this->echooutput) {
				echo 'Updating AniDB ID '.$anidbid."\n";
				}
			// actually get the information on this anime from anidb
			$AniDBAPIArrayNew = $this->AniDBAPI($anidbid);

			// if it is false we can simply exit
			if ($AniDBAPIArrayNew['banned']) {
				if ($this->echooutput) {
					echo $this->c->error("AniDB Banned, import will fail, please wait 24 hours before retrying.\n");
					}
				$this->c->doEcho($this->c->header("Exit getAniDBInfo at " . date('D M d, Y G:i a')));
				return;
				}
			// increment apicount on API access
			$apicount++;
			// update the stored information with updated data
			// lazy way: delete then insert
			$this->deleteTitle($anidbid);
			$this->addTitle($AniDBAPIArrayNew);

//			$this->updateTitle($AniDBAPIArrayNew['anidbid'], $AniDBAPIArrayNew['title'], $AniDBAPIArrayNew['type'],
//			$AniDBAPIArrayNew['startdate'], $AniDBAPIArrayNew['enddate'], $AniDBAPIArrayNew['related'],
//			$AniDBAPIArrayNew['creators'], $AniDBAPIArrayNew['description'], $AniDBAPIArrayNew['rating'],
//			$AniDBAPIArrayNew['categories'], $AniDBAPIArrayNew['characters'], $AniDBAPIArrayNew['epnos'],
//			$AniDBAPIArrayNew['airdates'], $AniDBAPIArrayNew['episodetitles']);

			$image_file = $this->imgSavePath . $anidbid;

			// if the image is present we do not need to replace it
			if (!file_exists($image_file)) {
				if ($AniDBAPIArrayNew['picture']) {
					// save the image to the covers page
					$ri->saveImage($AniDBAPIArrayNew['anidbid'],
					'http://img7.anidb.net/pics/anime/'.$AniDBAPIArrayNew['picture'], $this->imgSavePath);
					}
				}

			// update how many we have done of the total to do in this session
			if ($apicount != 0 && $this->echooutput) {
				echo 'Processed ' . $apicount . " anidb entries of a total possible of " . $exitcount . " for this session.\n";
				}
			// every 10 records sleep for 4 minutes before continuing
			if ($apicount % 10 == 0 && $apicount != 0) {
				$sleeptime=180 + rand(30, 90);
				}
			if ($this->echooutput) {
				$this->c->doEcho($this->c->primary("[".date('d-m-Y G:i')."] Start waitloop for " . $sleeptime . " sec to prevent banning."));
				}
			sleep($sleeptime);

			// using exitcount if this number of API calls is reached exit
			if ($apicount >= $exitcount) {
				$this->c->doEcho($this->c->header("Exit getAniDBInfo at " . date('D M d, Y G:i a')));
				return;
				}
			}

		// now for stage 3: update rest of records not updated for a loooooong time
		// same as step2: but other for loop (so we need to make a proper function out of this?!)
		$this->c->doEcho($this->c->header("[".date('d-m-Y G:i')."] Stage 3 -> Update 90+ day old series."));

		foreach($anidboldtitles as $value) {
			$anidbid = (int)$value['anidbid'];
			if ($this->echooutput) {
				echo 'Updating AniDB ID '.$anidbid."\n";
				}
			// actually get the information on this anime from anidb
			$AniDBAPIArrayNew = $this->AniDBAPI($anidbid);
			if ($AniDBAPIArrayNew['banned']) {
				if ($this->echooutput) {
					echo "AniDB Banned, import will fail, please wait 24 hours before retrying\n";
					}
				$this->c->doEcho($this->c->header("Exit getAniDBInfo at " . date('D M d, Y G:i a')));
				return;
				}
			// increment apicount on API access
			$apicount++;

			// update the stored information with updated data
			$this->updateTitle($AniDBAPIArrayNew['anidbid'], $AniDBAPIArrayNew['title'], $AniDBAPIArrayNew['type'],
			$AniDBAPIArrayNew['startdate'], $AniDBAPIArrayNew['enddate'], $AniDBAPIArrayNew['related'],
			$AniDBAPIArrayNew['creators'], $AniDBAPIArrayNew['description'], $AniDBAPIArrayNew['rating'],
			$AniDBAPIArrayNew['categories'], $AniDBAPIArrayNew['characters'], $AniDBAPIArrayNew['epnos'],
			$AniDBAPIArrayNew['airdates'], $AniDBAPIArrayNew['episodetitles']);

			$image_file = $this->imgSavePath . $anidbid;

			// if the image is present we do not need to replace it
			if (!file_exists($image_file)) {
				if ($AniDBAPIArrayNew['picture']) {
					// save the image to the covers page
					$ri->saveImage($AniDBAPIArrayNew['anidbid'],
					'http://img7.anidb.net/pics/anime/'.$AniDBAPIArrayNew['picture'], $this->imgSavePath);
					}
				}

			// update how many we have done of the total to do in this session
			if ($apicount != 0 && $this->echooutput) {
				echo 'Processed '.$apicount." anidb entries of a total possible of " . $exitcount . " for this session\n";
				}
			// every 10 records sleep for 4 minutes before continuing
			if ($apicount % 10 == 0 && $apicount != 0) {
				$sleeptime = 180 + rand(30, 90);
				if ($this->echooutput) {
					$this->c->doEcho($this->c->primary("[".date('d-m-Y G:i')."] Start waitloop for " . $sleeptime . " sec to prevent banning"));
					}
				sleep($sleeptime);
				}

			// using exitcount if this number of API calls is reached exit
			if ($apicount >= $exitcount) {
				return;
				$this->c->doEcho($this->c->header("Exit getAniDBInfo at " . date('D M d, Y G:i a')));
				}

			}
		$this->c->doEcho($this->c->header("Exit getAniDBInfo at " . date('D M d, Y G:i a')));

	} // end public function getAniDBInfo($exitcount)

	public function addTitle($AniDBAPIArray) {
		$db = $this->db;
		$db->queryInsert(sprintf("INSERT INTO anidb VALUES (%d, 0, 0, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d)",
			$AniDBAPIArray['anidbid'], $db->escapeString($AniDBAPIArray['title']), $db->escapeString($AniDBAPIArray['type']),
			(empty($AniDBAPIArray['startdate']) ? 'null' : $db->escapeString($AniDBAPIArray['startdate'])),
			(empty($AniDBAPIArray['enddate']) ? 'null' : $db->escapeString($AniDBAPIArray['enddate'])),
			$db->escapeString($AniDBAPIArray['related']), $db->escapeString($this->limitArrlen($AniDBAPIArray['creators'],1024)),
			$db->escapeString($AniDBAPIArray['description']), $db->escapeString($AniDBAPIArray['rating']),
			$db->escapeString($AniDBAPIArray['picture']), $db->escapeString($AniDBAPIArray['categories']),
			$db->escapeString($this->limitArrlen($AniDBAPIArray['characters'],1024)), $db->escapeString($AniDBAPIArray['epnos']),
			$db->escapeString($AniDBAPIArray['airdates']), $db->escapeString($AniDBAPIArray['episodetitles']), time()
			));

		$titlesmix = explode('|', $AniDBAPIArray['titles']);
		foreach ($titlesmix as $mix) {
			$titles = explode('~', $mix);
			$db->queryInsert(sprintf("INSERT INTO anidb_titles VALUES (%d, %s, %s, %s)",
					$AniDBAPIArray['anidbid'],
					$db->escapeString($titles[0]),
					$db->escapeString($titles[1]),
					$db->escapeString($titles[2])
				));
		}
echo "\nEnd addTitle\n";
	}

	public function updateTitle($anidbID, $title, $type, $startdate, $enddate, $related, $creators, $description, $rating, $categories, $characters, $epnos, $airdates, $episodetitles) {
		$db = $this->db;
              $db->queryExec(sprintf('UPDATE anidb SET title = %s, type = %s, startdate = %s, enddate = %s, related = %s, creators = %s, description = %s,
					rating = %s, categories = %s, characters = %s, epnos = %s, airdates = %s, episodetitles = %s, unixtime = %d
					WHERE anidbid = %d',
					$db->escapeString($title), $db->escapeString($type), (empty($AniDBAPIArray['startdate']) ? 'null' : $db->escapeString($AniDBAPIArray['startdate'])),
					(empty($AniDBAPIArray['enddate']) ? 'null' : $db->escapeString($AniDBAPIArray['enddate'])), $db->escapeString($related), $db->escapeString(limitArrlen($creators,1024)),
					$db->escapeString($description), $db->escapeString($rating), $db->escapeString($categories), $db->escapeString(limitArrlen($characters,1024)), $db->escapeString($epnos),
					$db->escapeString($airdates), $db->escapeString($episodetitles), time(), $anidbID
					));
// TODO: Update anidb_lang fields (or do this in separate function?!?!)
		}

	public function deleteTitle($anidbID) {
		$db = $this->db;
		$db->queryExec(sprintf('DELETE FROM anidb WHERE anidbid = %d', $anidbID));
		$db->queryExec(sprintf('DELETE FROM anidb_titles WHERE anidbid = %d', $anidbID));
		}

	public function getAnimeInfo($anidbID) {
		$db = $this->db;
		$animeInfo = $db->query(sprintf('SELECT * FROM anidb WHERE anidbid = %d', $anidbID
					));
		return isset($animeInfo[0]) ? $animeInfo[0] : false;
		}


	public function AniDBAPI($anidbID) {
		$ch = curl_init('http://api.anidb.net:9001/httpapi?request=anime&client='.$this->APIKEY.'&clientver='.self::CLIENTVER.'&protover=1&aid='.$anidbID);
		if ($this->echooutput) {
			echo 'http://api.anidb.net:9001/httpapi?request=anime&client='.$this->APIKEY.'&clientver='.self::CLIENTVER.'&protover=1&aid='.$anidbID."\n";
			}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
		$apiresponse = curl_exec($ch);

		if ($this->echooutput) {
			echo "Response: '".$apiresponse."'\n";
			}
		if (!$apiresponse) {
			echo "AniDB   : Error getting response.\n";
			return false;
		} else {
		curl_close($ch);

		// if we are banned simply return false
		if (preg_match("/\<error\>Banned\<\/error\>/", $apiresponse)) {
			$AniDBAPIArray['banned'] = true;
			return $AniDBAPIArray;
		} else {
			if (!preg_match('/anime id="\d+"/', $apiresponse, $valid)) {
				echo "AniDB   : No 'anime id' field found in response.\n";
				return false;
			}
		}
		}

		preg_match('/<title xml:lang="en" type="official">([^<]+)<\/title>/', $apiresponse, $safeTitle);
		if(!$safeTitle)
			preg_match('/<title xml:lang="x-jat" type="main">([^<]+)<\/title>/', $apiresponse, $safeTitle);

// replace xml:lang with lang: (workaround to get lang recognised by attributes(). There should be better way, so go and optimize this code!
		$apiresponse = preg_replace('/<title xml:lang="/', '/<title lang="', $apiresponse);

		$AniDBAPIXML = new SimpleXMLElement($apiresponse);

		if(!$AniDBAPIXML)
			return false;

		if($AniDBAPIXML->titles->title)
			foreach($AniDBAPIXML->titles->title as $titles) {
				$lang = (STRING)$titles->attributes()->lang;
				$type = (STRING)$titles->attributes()->type;
				$titlesArray[] = $lang."~".$type."~".(string) $titles;
			}

		if($AniDBAPIXML->relatedanime)
			foreach($AniDBAPIXML->relatedanime as $related)
				$relatedArray[] = (string) $related->anime;

		if($AniDBAPIXML->creators->name)
			foreach($AniDBAPIXML->creators->name as $creator)
				$creatorsArray[] = (string) $creator;

		if($AniDBAPIXML->categories->category)
			foreach($AniDBAPIXML->categories->category as $category)
				$categoriesArray[] = (string) $category->name;

		if($AniDBAPIXML->characters->character)
			foreach($AniDBAPIXML->characters->character as $character)
				$charactersArray[] = (string) $character->name;

// only english episode titles for now
		foreach($AniDBAPIXML->episodes->episode as $episode) {
			$epnosArray[] = (string) $episode->epno;
			$airdatesArray[] = (string) $episode->airdate;
			foreach($episode->title as $episodetitles) {
				if($episodetitles->attributes()->lang == 'en') {
					$episodetitlesArray[] = (string) $episodetitles;
				}
			}
		}

		if (isset($AniDBAPIXML->startdate[0])) {
			if (($timestamp = strtotime($AniDBAPIXML->startdate[0])) === false) {
				// Timestamp is bad -- set ''
				$startdate= '';
			}
			// Startdate valid for php, convert in case only year or month is given to sql date
			$startdate= date('Y-m-d', strtotime($AniDBAPIXML->startdate[0]));
		} else {
			$startdate = '';
		}

		if (isset($AniDBAPIXML->enddate[0])) {
			if (($timestamp = strtotime($AniDBAPIXML->enddate[0])) === false) {
				// Timestamp not good->make it null";
				$enddate = '';
			}
			// Startdate valid for php, convert in case only year or month is given to sql date
			$enddate = date('Y-m-d', strtotime($AniDBAPIXML->enddate[0]));
		} else {
			// echo "Null date ".$type_startenddate[2][2]."\n";
			$enddate = '';
		}

		if (isset($AniDBAPIXML->ratings->permanent)) {
			$rating = $AniDBAPIXML->ratings->permanent;
		}
		elseif (isset($AniDBAPIXML->ratings->temporary)) {
				$rating = $AniDBAPIXML->ratings->temporary;
		}
		else {
			$rating = '';
		}

		$AniDBAPIArray = array(
			'anidbid' => $anidbID,
			'title' => $safeTitle[1],
			'titles' => isset($titlesArray) ? implode($titlesArray, '|') : '',
			'type' => isset($AniDBAPIXML->type[0]) ? (string) $AniDBAPIXML->type[0] : '',
			'startdate' => (string) $startdate,
			'enddate' => (string) $enddate,
			'related' => isset($relatedArray) ? implode($relatedArray, '|') : '',
			'creators' => isset($creatorsArray) ? implode($creatorsArray, '|') : '',
			'description' => isset($AniDBAPIXML->description) ? (string) $AniDBAPIXML->description : '',
			'rating' =>  (string) $rating,
			'picture' => isset($AniDBAPIXML->picture[0]) ? (string) $AniDBAPIXML->picture[0] : '',
			'categories' => isset($categoriesArray) ? implode($categoriesArray, '|') : '',
			'characters' => isset($charactersArray) ? implode($charactersArray, '|') : '',
			'epnos' => isset($epnosArray) ? implode($epnosArray, '|') : '',
			'airdates' => isset($airdatesArray) ? implode($airdatesArray, '|') : '',
			'episodetitles' => isset($episodetitlesArray) ? implode($episodetitlesArray, '|') : '',
		);

		$sleeptime = 10 + rand(2, 10);

		if ($this->echooutput) {
			$this->c->doEcho($this->c->primary("[".date('d-m-Y G:i')."] Start waitloop for " . $sleeptime . " seconds to comply with flooding rule."));
		}
		sleep($sleeptime);
		return $AniDBAPIArray;
	} // end public function AniDBAPI($anidbID)


// function to limit imploded array
	public function limitArrlen($textin , $arrlen) {

		if (strlen($textin) >= $arrlen) {
			$textin = substr($textin, 0, ($arrlen+1));
			echo "\nLimited text(1): ".$textin;
			// AAA|BBB|CCC => len=3..6 -> AAA, len=7..11 -> AAA|BBB . pos | = 4&8 ==> next | from
			$chop=strrpos($textin, '|', 0);
			if ($chop === false) { echo "Chopping went wrong!!"; }
			else
				echo "\nChop position should be: ".$chop." ";
			$textin=substr($textin, 0, $chop);
		}
		return $textin;
	}
} // end class AniDBstandAlone

$c = new ColorCLI();

if (isset($argv[1]) && is_numeric($argv[1])) {
	// create a new AniDB object
	$anidb = new AniDBstandAlone(true);

	// next get the title list and populate the DB, update animetitles once a week
	$anidb->animetitlesUpdate();

	// sleep between 1 and 3 minutes before it starts, this way if from a cron process the start times are random
	if (isset($argv[2]) && $argv[2] == 'cron') {
		sleep(rand(60, 180));
		}
	// then get the titles, this is where we will make the real changes
	$anidb->getAniDBInfo((int)$argv[1] + rand(1, 12));
//	$anidb->getAniDBInfo((int)$argv[1]);
} else {
	echo $c->error("This script is designed to gather all show data from anidb and add it to the anidb table for nZEDb, as part of this process we need the number of API queries that can be executed max.\nTo execute this script run:\nphp populate_anidb.php 30\n");
}
