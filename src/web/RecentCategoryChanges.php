<?php
/**
 Copyright 2015 Myers Enterprises II

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

use com_brucemyers\CategoryWatchlistBot\UIHelper;
use com_brucemyers\Util\MySQLDate;
use com_brucemyers\Util\DateUtil;
use com_brucemyers\Util\HttpUtil;
use com_brucemyers\Util\L10N;

$webdir = dirname(__FILE__);
// Marker so include files can tell if they are called directly.
$GLOBALS['included'] = true;
$GLOBALS['botname'] = 'CategoryWatchlistBot';
define('BOT_REGEX', '!(?:spider|bot[\s_+:,\.\;\/\\\-]|[\s_+:,\.\;\/\\\-]bot)!i');
define('COOKIE_QUERYID', 'catwl:queryid');

//error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);
//ini_set("display_errors", 1);

require $webdir . DIRECTORY_SEPARATOR . 'bootstrap.php';

$uihelper = new UIHelper();
$wikis = $uihelper->getWikis();
$params = array();

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

switch ($action) {
    case 'statistics':
		$wiki = isset($_REQUEST['wiki']) ? $_REQUEST['wiki'] : 'enwiki';
		if (! isset($wikis[$wiki])) $wiki = 'enwiki';
		display_stats($wiki);
    	exit;
}

get_params();

$l10n = new L10N($wikis[$params['wiki']]['lang']);

display_form();

/**
 * Display form
 *
 */
function display_form()
{
	global $uihelper, $params, $wikis, $l10n;
    ?>
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
    <html xmlns="http://www.w3.org/1999/xhtml">
    <head>
	    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	    <meta name="robots" content="noindex, nofollow" />
	    <title><?php echo htmlentities($l10n->get('recentchangestitle'), ENT_COMPAT, 'UTF-8') ?></title>
    	<link rel='stylesheet' type='text/css' href='css/catwl.css' />
	    <style>
	        .plusminus {
                text-align: center;
            }
            </style>
    	<script type='text/javascript' src='js/jquery-2.1.1.min.js'></script>
		<script type='text/javascript' src='js/jquery.tablesorter.min.js'></script>
	</head>
	<body>
		<script type='text/javascript'>
			$(document).ready(function()
			    {
		        $('.tablesorter').tablesorter({ headers: { 1: {sorter:"text"} } });
			    }
			);
		</script>
		<div style="display: table; margin: 0 auto;">
		<h2><a href="RecentCategoryChanges.php?wiki=<?php echo $params['wiki'] ?>" class="novisited"><?php echo htmlentities($l10n->get('recentchangestitle'), ENT_COMPAT, 'UTF-8') ?></a></h2>
        <form action="RecentCategoryChanges.php" method="post"><b>Wiki</b> <select name="wiki"><?php
        foreach ($wikis as $wikiname => $wikidata) {
			$wikititle = htmlentities($wikidata['title'], ENT_COMPAT, 'UTF-8');
			$selected = '';
			if ($wikiname == $params['wiki']) $selected = ' selected="1"';
			$wikiname = htmlentities($wikiname, ENT_COMPAT, 'UTF-8');
			echo "<option value='$wikiname'$selected>$wikititle</option>";
		}
        ?></select><input type="submit" value="Submit" />
        </form>
    <?php

    display_recent();

    ?></div><br /><div style="display: table; margin: 0 auto;">
    <a href="RecentCategoryChanges.php?wiki=<?php echo urlencode($params['wiki']) ?>&amp;action=statistics" class='novisited'><?php echo htmlentities($l10n->get('statistics', true), ENT_COMPAT, 'UTF-8') ?></a> <b>&bull;</b>
    <a href="CategoryWatchlist.php" class='novisited'><?php echo htmlentities($l10n->get('watchlisttitle', true), ENT_COMPAT, 'UTF-8') ?></a> <b>&bull;</b>
    <a href="https://en.wikipedia.org/wiki/User:CategoryWatchlistBot" class='novisited'>Documentation</a> <b>&bull;</b>
    <?php echo htmlentities($l10n->get('author', true), ENT_COMPAT, 'UTF-8') ?>: <a href="https://en.wikipedia.org/wiki/User:Bamyers99" class='novisited'>Bamyers99</a></div></body></html><?php
}

/**
 * Display recent changes
 */
function display_recent()
{
	global $uihelper, $params, $wikis, $l10n;
	$errors = array();

	$results = $uihelper->getRecent($params['wiki'], $params['page'], 100);
	if (empty($results)) $errors[] = 'No more results';

	if (! empty($errors)) {
		echo '<h3>Messages</h3><ul>';
		foreach ($errors as $msg) {
			echo "<li>$msg</li>";
		}
		echo '</ul>';
	}

	if (! empty($results)) {
		$protocol = HttpUtil::getProtocol();
		$domain = $wikis[$params['wiki']]['domain'];
		$wikiprefix = "$protocol://$domain/wiki/";

		// Sort by date
		$dategroups = array();
		foreach ($results as &$result) {
			$date = $result['diffdate'];
			unset($result['diffdate']);
			if (! isset($dategroups[$date])) $dategroups[$date] = array();
			$dategroups[$date][] = $result;
		}
		unset($result);

		$redirectmsg = htmlentities(' (' . $l10n->get('redirect') . ')', ENT_COMPAT, 'UTF-8');

		foreach ($dategroups as $date => &$dategroup) {
			$displaydate = $l10n->formatDate(MySQLDate::toPHP($date), 'datetimefmt');
			echo "<h3>$displaydate</h3>";
			echo "<table class='wikitable tablesorter'><thead><tr><th>" .
				htmlentities($l10n->get('page', true), ENT_COMPAT, 'UTF-8') . "</th><th>+/&ndash;</th><th>" .
				htmlentities($l10n->get('category', true), ENT_COMPAT, 'UTF-8') . " / " .
				htmlentities($l10n->get('template', true), ENT_COMPAT, 'UTF-8') . "</th></tr></thead><tbody>\n";
			$x = 0;
			$prevtitle = '';
			$prevaction = '';

			foreach ($dategroup as &$result) {
				$title = $result['pagetitle'];
				$action = $result['plusminus'];
				$category = htmlentities($result['category'], ENT_COMPAT, 'UTF-8');
				if ($result['cat_template'] == 'T') $category = '{{' . $category . '}}';
				$displayaction = ($action == '-') ? '&ndash;' : $action;
				$flags = $result['flags'];

				if ($title == $prevtitle && $action == $prevaction) {
					echo "; $category";
				} elseif ($title == $prevtitle) {
					echo "</td></tr>\n";
					echo "<tr><td>&nbsp;</td><td class='plusminus'>$displayaction</td><td>$category";
				} else {
					if ($x++ > 0) echo "</td></tr>\n";
					$redirectadd = '';
					if ($flags & 1) $redirectadd = $redirectmsg;

					echo "<tr><td><a href=\"$wikiprefix" . urlencode(str_replace(' ', '_', $title)) . "\">" .
						htmlentities($title, ENT_COMPAT, 'UTF-8') . "</a>$redirectadd</td><td class='plusminus'>$displayaction</td><td>$category";
				}
				$prevtitle = $title;
				$prevaction = $action;
			}

			if ($x > 0) echo "</td></tr>\n";

			echo "</tbody></table>\n";
		}
		unset($dategroup);
		unset($result);

		$host  = $_SERVER['HTTP_HOST'];
		$uri   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
		$protocol = HttpUtil::getProtocol();

		$extra = "RecentCategoryChanges.php?wiki={$params['wiki']}&amp;page=" . ($params['page'] + 1);
		echo "<div style='padding-bottom: 10px;'><a href='$protocol://$host$uri/$extra' class='novisited'>" .
				htmlentities($l10n->get('nextpage', true), ENT_COMPAT, 'UTF-8') . "</a></div>";

		echo '<div style="padding-bottom: 10px;">+ = ' . htmlentities($l10n->get('added', true), ENT_COMPAT, 'UTF-8').
			'<br />&ndash; = ' . htmlentities($l10n->get('removed', true), ENT_COMPAT, 'UTF-8'). '</div>';
		}
}

/**
 * Display statistics
 *
 * @param string $wiki Wiki
 */
function display_stats($wiki)
{
	global $uihelper, $wikis;

	$l10n = new L10N($wikis[$wiki]['lang']);
	$stats = $uihelper->getStatistics($wiki);

	$title = htmlentities($l10n->get('watchlisttitle'), ENT_COMPAT, 'UTF-8') . ' : ' . htmlentities($l10n->get('statistics', true), ENT_COMPAT, 'UTF-8');
    echo '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
    echo '<html xmlns="http://www.w3.org/1999/xhtml">';
    echo '<head>';
	echo '   <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
	echo '   <meta name="robots" content="noindex, nofollow" />';
	echo "   <title>$title</title>";
    echo "	 <link rel='stylesheet' type='text/css' href='css/catwl.css' />";
    echo "   <script type='text/javascript' src='js/jquery-2.1.1.min.js'></script>";
	echo "   <script type='text/javascript' src='js/jquery.tablesorter.min.js'></script>";
    echo '</head>';
	echo "<body>\n";
	echo "<script type='text/javascript'>\n";
	echo "$(document).ready(function()\n";
	echo "{\n";
	echo "	$('.tablesorter').tablesorter({ headers: { 1: {sorter:\"text\"} } });\n";
	echo "}\n";
	echo ");\n";
	echo "</script>\n";

	echo "<center><h3>$title</h3></center>";
    echo '<div style="display: table; margin: 0 auto;"><form action="RecentCategoryChanges.php" method="post"><b>Wiki</b> <select name="wiki">';
        foreach ($wikis as $wikiname => $wikidata) {
			$wikititle = htmlentities($wikidata['title'], ENT_COMPAT, 'UTF-8');
			$selected = '';
			if ($wikiname == $wiki) $selected = ' selected="1"';
			$wikiname = htmlentities($wikiname, ENT_COMPAT, 'UTF-8');
			echo "<option value='$wikiname'$selected>$wikititle</option>";
		}
    echo '</select><input type="hidden" name="action" value="statistics" /><input type="submit" value="Submit" />';
    echo '</form></div>';

	echo "<br /><div style='display: table; margin: 0 auto;'>";
	echo htmlentities($l10n->get('eventspastweek'), ENT_COMPAT, 'UTF-8') . ": " . $stats['totalEvents'];

	$counthead = htmlentities($l10n->get('count', true), ENT_COMPAT, 'UTF-8');

	$category = htmlentities($l10n->get('category', true), ENT_COMPAT, 'UTF-8');
	$template = htmlentities($l10n->get('template', true), ENT_COMPAT, 'UTF-8');
	$added = htmlentities($l10n->get('added'), ENT_COMPAT, 'UTF-8');
	$removed = htmlentities($l10n->get('removed'), ENT_COMPAT, 'UTF-8');

	$top10s = array('C+' => "$category $added", 'C-' => "$category $removed",
		'T+'=> "$template $added", 'T-'=> "$template $removed");

	foreach ($top10s as $key => $label) {
		echo "<h3>$label</h3>";
		$colhead = ($key[0] == 'C') ? $category : $template;
		echo "<table class='wikitable'><tr><th>&nbsp;</th><th>$colhead</th><th>$counthead</th></tr>";
		$count = 1;

		foreach ($stats[$key] as $total) {
			$cat = htmlentities($total['category'], ENT_COMPAT, 'UTF-8');
			echo "<tr><td>$count</td><td>$cat</td><td style='text-align:right'>{$total['catcount']}</td></tr>";
			++$count;
		}

		echo '</table>';
	}

    echo '</div><br /><div style="display: table; margin: 0 auto;">';
    echo '<a href="RecentCategoryChanges.php?wiki=' . urlencode($wiki) . '" class="novisited">' . htmlentities($l10n->get('recentcategorychanges'), ENT_COMPAT, 'UTF-8') . '</a> <b>&bull;</b> ';
    echo '<a href="https://en.wikipedia.org/wiki/User:CategoryWatchlistBot" class="novisited">Documentation</a> <b>&bull;</b> ';
    echo htmlentities($l10n->get('author', true), ENT_COMPAT, 'UTF-8') . ': <a href="https://en.wikipedia.org/wiki/User:Bamyers99" class="novisited">Bamyers99</a></div></body></html>';
}

/**
 * Get the input parameters
 */
function get_params()
{
	global $params, $wikis, $uihelper;

	$params = array();

	$params['page'] = isset($_REQUEST['page']) ? $_REQUEST['page'] : '1';

	$params['wiki'] = isset($_REQUEST['wiki']) ? $_REQUEST['wiki'] : '';
	if (! isset($wikis[$params['wiki']])) $params['wiki'] = 'enwiki';
}
?>