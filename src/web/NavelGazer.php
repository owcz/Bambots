<?php
/**
 Copyright 2016 Myers Enterprises II

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

 http://www.apache.org/licenses/LICENSE-2.0

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
 */

use com_brucemyers\Util\Config;
use com_brucemyers\CleanupWorklistBot\CleanupWorklistBot;
use com_brucemyers\Util\CSVString;
use com_brucemyers\MediaWiki\WikidataWiki;

$webdir = dirname(__FILE__);
// Marker so include files can tell if they are called directly.
$GLOBALS['included'] = true;
$GLOBALS['botname'] = 'CleanupWorklistBot';
error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);
ini_set("display_errors", 1);

require $webdir . DIRECTORY_SEPARATOR . 'bootstrap.php';

$params = array();

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

get_params();

switch ($action) {
	case 'getCSV':
		getCSV();
		exit;
}

$navels = get_navels();

display_form($navels);

/**
 * Display replag
 *
 */
function display_form($navels)
{
	global $params;
	$edit_types = array(
		-1 => 'Label additions',
		-2 => 'Description additions',
		-3 => 'Alias additions',
		-4 => 'Site link additions',
		-5 => 'Merges'
	);

    ?>
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
    <html xmlns="http://www.w3.org/1999/xhtml">
    <head>
	    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	    <meta name="robots" content="noindex, nofollow" />
	    <title>Wikidata Navel Gazer</title>
    	<link rel='stylesheet' type='text/css' href='css/catwl.css' />
	    <script type='text/javascript' src='js/jquery-2.1.1.min.js'></script>
		<script type='text/javascript' src='js/jquery.tablesorter.min.js'></script>
	</head>
	<body>
		<script type='text/javascript'>
			$(document).ready(function()
			    {
		        $('.tablesorter').tablesorter();
			    }
			);
		</script>
		<div style="display: table; margin: 0 auto;">
		<h2>Wikidata Navel Gazer<sup>[1]</sup><?php
		if (! empty($params['username'])) echo ' - ' . $params['username'];
		elseif (! empty($params['property'])) {
			if ($params['property'] < 0) {
				if (isset($edit_types[$params['property']])) echo ' - ' . $edit_types[$params['property']];
			} else {
				echo ' - P' . $params['property'];
			}
		}
		?></h2>
		<h3>users statement addition counts</h3>
        <form action="NavelGazer.php" method="post">
        <table class="form">
        <tr><td><b>Username</b></td><td><input id="username" name="username" type="text" size="10" value="<?php echo $params['username'] ?>" /></td></tr>
        <tr><td colspan='2'>or</td></tr>
        <tr><td><b>Property</b></td><td><input id="property" name="property" type="text" size="10" value="<?php if (! empty($params['property'])) echo 'P' . $params['property'] ?>" /> example: P31</td></tr>
        <tr><td>Pseudo properties</td><td><?php
         	foreach ($edit_types as $key => $edit_type) {
         		if ($key != -1) echo ', ';
        		if ($key == -3) echo '<br />';
        		echo "P$key = {$edit_type}";
        	}
        ?></td></tr>
        <tr><td colspan='2'><hr /></td></tr>
        <tr><td><b>Property label language code</b></td><td><input id="lang" name="lang" type="text" size="4" value="<?php echo $params['lang'] ?>" /></td></tr>
        <tr><td colspan='2'><input type="submit" value="Submit" /></td></tr>
        </table>
        </form>
        <script type="text/javascript">
            if (document.getElementById) {
                var e = document.getElementById('username');
                e.focus();
                e.select();
            }
        </script>
        <br />
<?php
	if (! empty($navels)) {
		if (empty($navels['data'])) {
			if (! empty($params['username'])) echo 'User does not have any property additions';
			else echo 'Property not found';

		} elseif (! empty($params['username'])) {
			if (! empty($params['property'])) echo "Clear the Username field to do a property search<br />\n";
			echo $navels['dataasof'] . "<sup>[2]</sup><br /><br />\n";

			$misc = array();

			foreach ($navels['data'] as $key => $row) {
				if ($row[0] < 0) {
					$misc[] = $edit_types[$row[0]] . ": {$row[1]} (last month: {$row[2]})<br />\n";
					unset($navels['data'][$key]);
				}
			}

			if (! empty($misc)) {
				sort($misc);
				foreach ($misc as $statement) {
					echo $statement;
				}
			}

			echo "<table class='wikitable tablesorter'><thead><tr><th>Property</th><th>Total count</th><th>Last month</th></tr></thead><tbody>\n";

			usort($navels['data'], function($a, $b) {
				return strcmp(strtolower($a[3]), strtolower($b[3]));
			});

			foreach ($navels['data'] as $row) {
				$url = "/bambots/NavelGazer.php?property=P" . $row[0];
				$term_text = htmlentities($row[3], ENT_COMPAT, 'UTF-8');
				echo "<tr><td><a href='$url'>$term_text (P{$row[0]})</a></td><td style='text-align:right' data-sort-value='$row[1]'>" . intl_num_format($row[1]) .
					"</td><td style='text-align:right' data-sort-value='$row[2]'>" . intl_num_format($row[2]) . "</td></tr>\n";
			}

			echo "</tbody></table>\n";

		} else { // property
			echo $navels['dataasof'] . "<sup>[2]</sup><br />\n";
			if ($params['property'] < 0) {
				if (isset($edit_types[$params['property']])) echo 'Action: ' . $edit_types[$params['property']] . "<br />\n";
			} else {
				$url = "https://www.wikidata.org/wiki/Property:P" . $params['property'];
				$term_text = htmlentities($navels['property_label'], ENT_COMPAT, 'UTF-8');
				echo "Property: <a href='$url' class='external'>$term_text (P{$params['property']})</a><br />\n";
			}
			if (count($navels['data']) == 100) echo "Top 100<br />\n";

			echo "<table class='wikitable tablesorter'><thead><tr><th>Username</th><th>Total count</th><th>Last month</th></tr></thead><tbody>\n";

			foreach ($navels['data'] as $row) {
				$user_encoded = htmlentities($row[0], ENT_COMPAT, 'UTF-8');
				$url = "/bambots/NavelGazer.php?username=" . urlencode($row[0]);
				echo "<tr><td><a href='$url'>$user_encoded</a></td><td style='text-align:right' data-sort-value='$row[1]'>" . intl_num_format($row[1]) .
					"</td><td style='text-align:right' data-sort-value='$row[2]'>" . intl_num_format($row[2]) . "</td></tr>\n";
			}

			echo "</tbody></table>\n";

			$host  = $_SERVER['HTTP_HOST'];
			$uri   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
			$extra = "NavelGazer.php?action=getCSV&property=P" . urlencode($params['property']);

			echo "<a href='//$host$uri/$extra'>Download all users in CSV format</a>\n<br />";
		}
	}
?>
        <br /><div><sup>1</sup><a href='http://www.merriam-webster.com/dictionary/navel-gazing'>navel–gazing</a> (Merriam-Webster)</div>
        <div><sup>2</sup>Data derived from database dump wikidatawiki-stub-meta-history.xml revision comments</div>
        <div>Author: <a href="https://www.wikidata.org/wiki/User:Bamyers99">Bamyers99</a></div></div></body></html><?php
}

function get_navels()
{
	global $params;
	$return = array();
	$data = array();
	$property_label = '';
	$wdwiki = new WikidataWiki();

	if (empty($params['username']) && empty($params['property'])) return $return;

	$user = Config::get(CleanupWorklistBot::LABSDB_USERNAME);
	$pass = Config::get(CleanupWorklistBot::LABSDB_PASSWORD);
	$wiki_host = Config::get('CleanupWorklistBot.wiki_host'); // Used for testing
	if (empty($wiki_host)) $wiki_host = "tools.db.svc.eqiad.wmflabs";

	$dbh_wiki = new PDO("mysql:host=$wiki_host;dbname=s51454__wikidata;charset=utf8", $user, $pass);
	$dbh_wiki->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$sth = $dbh_wiki->query("SELECT user_name FROM s51454__wikidata.navelgazer WHERE user_name LIKE 'Data as of:%'");

	$row = $sth->fetch(PDO::FETCH_NUM);
	$dataasof = $row[0];

	if (! empty($params['username'])) {
		if (stripos($params['username'], 'User:') === 0) $params['username'] = substr($params['username'], 5);
		if (empty($params['username'])) return $return;
		$params['username'] = ucfirst($params['username']);
		$params['username'] = str_replace('_', ' ', $params['username']);

		$sql = "SELECT property_id, create_count, month_count " .
				" FROM s51454__wikidata.navelgazer " .
				" WHERE user_name = ? ";

		$sth = $dbh_wiki->prepare($sql);
		$sth->bindValue(1, $params['username']);

		$sth->execute();

		while ($row = $sth->fetch(PDO::FETCH_NAMED)) {
			$data[$row['property_id']] = array($row['property_id'], $row['create_count'], $row['month_count'], ''); // removes dups
		}

		if (! empty($data)) {
			$prop_ids = array();
			foreach (array_keys($data) as $key) {
				if ($key > 0) $prop_ids[] = 'Property:P' . $key;
			}

			$items = $wdwiki->getItemsNoCache($prop_ids);

			foreach ($items as $item) {
				$pid = $item->getId();
				$pid = substr($pid, 1);
				$property_label = $item->getLabelDescription('label', $params['lang']);

				if (! empty($property_label)) $data[$pid][3] = $property_label;
			}
		}

	} else { // property
		$items = $wdwiki->getItemsNoCache('Property:P' . $params['property']);

		if (! empty($items)) {
			$property_label = $items[0]->getLabelDescription('label', $params['lang']);
		}

		$sql = "SELECT user_name, create_count, month_count " .
				" FROM s51454__wikidata.navelgazer " .
				" WHERE property_id = ? ORDER by create_count DESC LIMIT 100";

		$sth = $dbh_wiki->prepare($sql);
		$sth->bindValue(1, (int)$params['property']);

		$sth->execute();

		while ($row = $sth->fetch(PDO::FETCH_NAMED)) {
			$data[] = array($row['user_name'], $row['create_count'], $row['month_count']);
		}
	}

	$return = array('data' => $data, 'dataasof' => $dataasof, 'property_label' => $property_label);

	return $return;
}

/**
 * Get the input parameters
 */
function get_params()
{
	global $params;

	$params = array();

	$params['username'] = isset($_REQUEST['username']) ? $_REQUEST['username'] : '';
	$params['property'] = isset($_REQUEST['property']) ? $_REQUEST['property'] : '';
	if (! empty($params['property']) && $params['property'][0] == 'P') $params['property'] = substr($params['property'], 1);
	$params['lang'] = isset($_REQUEST['lang']) ? $_REQUEST['lang'] : '';

	if (! empty($params['lang']) && preg_match('!([a-zA-Z]+)!', $params['lang'], $matches)) {
		$params['lang'] = strtolower($matches[1]);
	}

	if (empty($params['lang']) && isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && preg_match('!([a-zA-Z]+)!', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $matches)) {
		$params['lang'] = strtolower($matches[1]);
	}
	if (empty($params['lang'])) $params['lang'] = 'en';

}

/**
 * Format an integer
 *
 * @param int $number
 */
function intl_num_format($number)
{
	return number_format($number, 0, '', '&thinsp;');
}

/**
 * Return CSV of property user counts
 */
function getCSV()
{
	global $params;
	$wikiname = 'tools';
	$user = Config::get(CleanupWorklistBot::LABSDB_USERNAME);
	$pass = Config::get(CleanupWorklistBot::LABSDB_PASSWORD);
	$wiki_host = Config::get('CleanupWorklistBot.wiki_host'); // Used for testing
	if (empty($wiki_host)) $wiki_host = "$wikiname.labsdb";

	$dbh_wiki = new PDO("mysql:host=$wiki_host;dbname=s51454__wikidata;charset=utf8", $user, $pass);
	$dbh_wiki->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$sth = $dbh_wiki->query("SELECT user_name FROM s51454__wikidata.navelgazer WHERE user_name LIKE 'Data as of:%'");

	$row = $sth->fetch(PDO::FETCH_NUM);
	$dataasof = substr($row[0], 12);

	$filename = "NavelGazer_P{$params['property']}_$dataasof.csv";

	header('Content-Type: text/csv');
	header('Content-Disposition: attachment; filename="'.$filename.'";');

	echo CSVString::format(array('Username', 'Total count', 'Last month'));
	echo "\n";

	$sql = "SELECT user_name, create_count, month_count " .
			" FROM s51454__wikidata.navelgazer " .
			" WHERE property_id = ? ORDER by create_count DESC";

	$sth = $dbh_wiki->prepare($sql);
	$sth->bindValue(1, (int)$params['property']);

	$sth->execute();

	while ($row = $sth->fetch(PDO::FETCH_NAMED)) {
		echo CSVString::format(array($row['user_name'], $row['create_count'], $row['month_count']));
		echo "\n";
	}
}

?>