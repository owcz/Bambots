<?php
/**
 Copyright 2014 Myers Enterprises II

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

namespace com_brucemyers\CleanupWorklistBot;

use PDO;
use SplFixedArray;
use com_brucemyers\Util\CSVString;
use com_brucemyers\MediaWiki\ResultWriter;
use com_brucemyers\CleanupWorklistBot\CreateTables;
use com_brucemyers\CleanupWorklistBot\Categories;

class ReportGenerator
{
	static $MONTHS = array(1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
		7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December');
	var $outputdir;
	var $urlpath;
	var $tools_host;
	var $asof_date;
	var $resultWriter;
	var $categories;
	var $catobj;
	var $user;
	var $pass;

	// Key atoms to save memory
	const KEY_IMP = 0;
	const KEY_CLS = 1;
	const KEY_ISSUES = 2;
	const KEY_TITLE = 3;
	const KEY_MTH = 4;
	const KEY_YR = 5;
	const KEY_CLSSORT = 6;
	const KEY_IMPSORT = 7;
	const KEY_EARLIEST = 8;
	const KEY_EARLIESTSORT = 9;
	const KEY_CATS = 10;
	const KEY_ICOUNT = 11;

	function __construct($tools_host, $outputdir, $urlpath, $asof_date, ResultWriter $resultWriter, $catobj, $user, $pass)
	{
		$this->tools_host = $tools_host;
		$this->outputdir = $outputdir;
		$this->urlpath = $urlpath;
		$this->asof_date = $asof_date;
        $this->resultWriter = $resultWriter;
        $this->catobj = $catobj;
		$this->user = $user;
		$this->pass = $pass;
	}

	function generateReports($project, $isWikiProject, $project_pages)
	{
    	$dbh_tools = new PDO("mysql:host={$this->tools_host};dbname=s51454__CleanupWorklistBot;charset=utf8", $this->user, $this->pass);
   		$dbh_tools->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		$cleanup_pages = 0;
		$issue_count = 0;
		$added_pages = 0;
		$removed_pages = 0;
		$curclean = array();
		$asof_date = $this->asof_date['month'] . ' '. $this->asof_date['mday'] . ', ' . $this->asof_date['year'];
		$groups = array();
		$this->categories = array();
		$titles = array();
		$project_title = str_replace('_', ' ', $project);
		$filesafe_project = str_replace('/', '_', $project);
		$clquery = $dbh_tools->prepare('SELECT cat_id FROM categorylinks WHERE cl_from = ?');
		$expiry = strtotime('+1 week');
		$expiry = date('D, d M Y', $expiry) . ' 00:00:00 GMT';

		$results = $dbh_tools->query('SELECT `article_id`, `page_title`, `importance`, `class` FROM `page` p');

		while ($row = $results->fetch(PDO::FETCH_ASSOC)) {
			$pageid = (int)$row['article_id'];

			$clquery->bindValue(1, $pageid);
			$clquery->execute();

			$title = str_replace('_', ' ', $row['page_title']);
			if ($row['importance'] == null) $row['importance'] = '';
			if ($row['class'] == null) $row['class'] = '';
			$curclean[$title] = array(self::KEY_IMP => $row['importance'], self::KEY_CLS => $row['class'], self::KEY_ISSUES => array());
			++$cleanup_pages;

			while ($clrow = $clquery->fetch(PDO::FETCH_ASSOC)) {
				$cat_id = (int)$clrow['cat_id'];
				++$issue_count;
				if (! isset($this->categories[$cat_id])) {
					$cat = $this->catobj->categories[$cat_id];
					$this->categories[$cat_id] = array(self::KEY_TITLE => $cat['t'], self::KEY_MTH => $cat['m'], self::KEY_YR => $cat['y']);
				}
				$curclean[$title][self::KEY_ISSUES][] = $cat_id;
			}

			$clquery->closeCursor();
		}

		ksort($curclean);

		$results->closeCursor();
		$results = null;

		$artcleanpct = round(($cleanup_pages / $project_pages) * 100, 0);

		$wikiproject = ($isWikiProject) ? 'WikiProject_' : '';
		$projecturl = "https://en.wikipedia.org/wiki/Wikipedia:{$wikiproject}" . $project;
		$histurl = $this->urlpath . 'history/' . $filesafe_project . '.html';
		$bycaturl = $this->urlpath . 'bycat/' . $filesafe_project . '.html';
		$wikiprefix = 'https://en.wikipedia.org/wiki/';

		$csvurl = $this->urlpath . 'csv/' . $filesafe_project . '.csv';
		$csvpath = $this->outputdir . 'csv' . DIRECTORY_SEPARATOR . $filesafe_project . '.csv';
		$bakcsvpath = $this->outputdir . 'csv' . DIRECTORY_SEPARATOR . $filesafe_project . '.csv.bak';
		$tmpcsvpath = $this->outputdir . 'csv' . DIRECTORY_SEPARATOR . $filesafe_project . '.csv.tmp';

		// Save the previous csv.
		if (file_exists($csvpath)) {
			@unlink($bakcsvpath);
			rename($csvpath, $bakcsvpath);
		}

		// Load the previous csv to detect changes.
		$prevclean = array();

		if (file_exists($bakcsvpath)) {
			$hndl = fopen($bakcsvpath, 'rb');
			$x = 0;

			while (! feof($hndl)) {
				$buffer = rtrim(fgets($hndl));
				if (strlen($buffer) == 0) continue; // Skip empty lines
				if ($x++ == 0) continue; // Skip header
				$fields = CSVString::parse($buffer);
				$title = $fields[0];
				array_shift($fields);
				if (isset($curclean[$title])) $prevclean[$title] = true;
				else $prevclean[$title] = SplFixedArray::fromArray($fields, false);
			}
			fclose($hndl);
		}

		//
		// Write alpha and csv
		//

		$csvhndl = fopen($tmpcsvpath, 'wb');
		fwrite($csvhndl, '"Article","Importance","Class","Count","Oldest month","Categories"' . "\n");

		$alphaurl = $this->urlpath . 'alpha/' . $filesafe_project . '.html';
		$alphapath = $this->outputdir . 'alpha' . DIRECTORY_SEPARATOR . $filesafe_project . '.html';
		$alphahndl = fopen($alphapath, 'wb');
		$wikiproject = ($isWikiProject) ? 'WikiProject ' : '';
		fwrite($alphahndl, "<!DOCTYPE html>
			<html><head>
			<meta http-equiv='Content-type' content='text/html;charset=UTF-8' />
			<meta http-equiv='Expires' content='$expiry' />
			<title>Cleanup listing for {$wikiproject}{$project_title}</title>
    		<link rel='stylesheet' type='text/css' href='../../css/cwb.css' />
			<script type='text/javascript' src='../../js/jquery-2.1.1.min.js'></script>
			<script type='text/javascript' src='../../js/jquery.tablesorter.min.js'></script>
			</head><body>
			<script type='text/javascript'>
				$(document).ready(function()
				    {
				        $('#myTable').tablesorter({ headers: { 5: { sorter: false} } });
				    }
				);
			</script>
			<p>Cleanup listing for <a href=\"$projecturl\">{$wikiproject}{$project_title}</a> as of $asof_date.</p>
			<p>Of the $project_pages articles in this project $cleanup_pages or $artcleanpct% are marked for cleanup, with $issue_count issues in total.</p>
			<p>Listings: Alphabetic <b>&bull;</b> <a href=\"$bycaturl\">By category</a> <b>&bull;</b> <a href=\"$csvurl\">CSV</a> <b>&bull;</b> <a href=\"$histurl\">History</a></p>
			<table id='myTable' class='wikitable'><thead><tr><th>Article</th><th>Importance</th><th>Class</th><th>Count</th>
				<th>Oldest</th><th class='unsortable'>Issues</th></tr></thead><tbody>
    		");

		foreach ($curclean as $title => &$art) {
			$arturl = $wikiprefix . urlencode(str_replace(' ', '_', $title));
			$consolidated = $this->_consolidateCats($art[self::KEY_ISSUES]);
			$cats = implode(', ', $consolidated['issues']);
			$icount = count($art[self::KEY_ISSUES]);

			fwrite($csvhndl, CSVString::format(array($title, $art[self::KEY_IMP], $art[self::KEY_CLS], $icount, $consolidated['earliest'], $cats)) . "\n");

			$consolidated = $this->_consolidateCats($art[self::KEY_ISSUES], true);
			$cats = implode(', ', $consolidated['issues']);

			$clssort = CreateTables::$CLASSES[$art[self::KEY_CLS]];
			$impsort = CreateTables::$IMPORTANCES[$art[self::KEY_IMP]];

			fwrite($alphahndl, "<tr><td><a href=\"$arturl\">" . htmlentities($title, ENT_COMPAT, 'UTF-8') . "</a></td><td data-sort-value='$impsort'>{$art[self::KEY_IMP]}</td>
				<td data-sort-value='$clssort'>{$art[self::KEY_CLS]}</td><td align='right'>$icount</td>
				<td data-sort-value='{$consolidated['earliestsort']}'>{$consolidated['earliest']}</td><td>$cats</td></tr>\n");

			$titles[$title]= array(self::KEY_CLS => $art[self::KEY_CLS],
				self::KEY_CLSSORT => $clssort, self::KEY_IMP => $art[self::KEY_IMP],
				self::KEY_IMPSORT => $impsort, self::KEY_EARLIEST => $consolidated['earliest'],
				self::KEY_EARLIESTSORT => $consolidated['earliestsort'],
				self::KEY_CATS => $consolidated['issues'], self::KEY_ICOUNT => $icount);

			// Group by cat
			foreach ($art[self::KEY_ISSUES] as $cat_id) {
				$cat = str_replace('_', ' ', $this->categories[$cat_id][self::KEY_TITLE]);
				if (! isset($groups[$cat])) $groups[$cat] = array();
				$groups[$cat][$title] = true;
			}

			if (isset($prevclean[$title])) $art = true; // Free up the memory
		}
		unset($art);

		fwrite($alphahndl, "</tbody></table>Generated by <a href='https://en.wikipedia.org/wiki/User:CleanupWorklistBot' class='novisited'>CleanupWorklistBot</a></body></html>");


		fclose($csvhndl);
		fclose($alphahndl);

		// Calculate section anchors
		$anchors = array();

		foreach (Categories::$CATEGORIES as $catname => $catparams) {
			$displayname = $catname;
			if (isset($catparams['display'])) $displayname = $catparams['display'];

			if (! isset($anchors[$displayname])) $anchors[$displayname] = array();
			if (! in_array($displayname, $anchors[$displayname])) $anchors[$displayname][] = $displayname;
			if (isset($catparams['display'])) $anchors[$displayname][] = $catname;
		}

		foreach (Categories::$SHORTCATS as $catname => $displayname) {
			if (! isset($anchors[$displayname])) $anchors[$displayname] = array();
			if (! in_array($displayname, $anchors[$displayname])) $anchors[$displayname][] = $displayname;
			$anchors[$displayname][] = $catname;
		}

		// Group the cats
		$catgroups = array();
		ksort($groups);

		foreach ($groups as $cat => &$group) {
			if (isset(Categories::$parentCats[$cat])) $testcat = Categories::$parentCats[$cat];
			else $testcat = $cat;

			if (isset(Categories::$CATEGORIES[$testcat]['group'])) $catgroup = Categories::$CATEGORIES[$testcat]['group'];
			else $catgroup = 'General';

			if (isset(Categories::$CATEGORIES[$cat]['display'])) $cat = Categories::$CATEGORIES[$cat]['display'];
			elseif (isset(Categories::$SHORTCATS[$cat])) $cat = Categories::$SHORTCATS[$cat];

			if (! isset($catgroups[$catgroup])) $catgroups[$catgroup] = array();
			if (isset($catgroups[$catgroup][$cat])) {
				$catgroups[$catgroup][$cat] = $catgroups[$catgroup][$cat] + $group;
			}
			else $catgroups[$catgroup][$cat] = $group;
		}
		unset($group);
		unset($groups);

		ksort($catgroups);


		$bycatpath = $this->outputdir . 'bycat' . DIRECTORY_SEPARATOR . $filesafe_project . '.html';
		$bycathndl = fopen($bycatpath, 'wb');

		fwrite($bycathndl, "<!DOCTYPE html>
			<html><head>
			<meta http-equiv='Content-type' content='text/html;charset=UTF-8' />
			<meta http-equiv='Expires' content='$expiry' />
			<title>Cleanup listing for {$wikiproject}{$project_title}</title>
    		<link rel='stylesheet' type='text/css' href='../../css/cwb.css' />
			<script type='text/javascript' src='../../js/jquery-2.1.1.min.js'></script>
			<script type='text/javascript' src='../../js/jquery.tablesorter.min.js'></script>
			</head><body>
			<script type='text/javascript'>
				$(document).ready(function()
				    {
				        $('.tablesorter').tablesorter({ headers: { 6: { sorter: false} } });
				    }
				);
			</script>
			<p>Cleanup listing for <a href=\"$projecturl\">{$wikiproject}{$project_title}</a> as of $asof_date.</p>
			<p>Of the $project_pages articles in this project $cleanup_pages or $artcleanpct% are marked for cleanup, with $issue_count issues in total.</p>
			<p>Listings: <a href=\"$alphaurl\">Alphabetic</a> <b>&bull;</b> By category <b>&bull;</b> <a href=\"$csvurl\">CSV</a> <b>&bull;</b> <a href=\"$histurl\">History</a></p>
			<p>... represents the current issue name. Issue names are abbreviated category names.</p>
    		");

		// Write the TOC
		fwrite($bycathndl, "<div class='toc'><center>Contents</center>\n");
		fwrite($bycathndl, "<ul>\n");

		if (! empty($prevclean)) {
			fwrite($bycathndl, "<li><a href='#Changes since last update'>Changes since last update</a></li>\n");
			fwrite($bycathndl, "<ul>\n");
			$newarts = array_diff_key($curclean, $prevclean);
			$artcount = count($newarts);
			$added_pages = $artcount;

			fwrite($bycathndl, "<li><a href='#New articles'>New articles ($artcount)</a></li>\n");

			$resarts = array_diff_key($prevclean, $curclean);
			$artcount = count($resarts);
			$removed_pages = $artcount;

			fwrite($bycathndl, "<li><a href='#Resolved articles'>Resolved articles ($artcount)</a></li>\n");
			fwrite($bycathndl, "</ul>\n");
		}

		foreach ($catgroups as $catgroup => &$cats) {
			fwrite($bycathndl, "<li><a href='#$catgroup'>$catgroup</a></li>\n");
			fwrite($bycathndl, "<ul>\n");

			ksort($cats);

			foreach ($cats as $cat => &$arts) {
				$artcount = count($arts);
				fwrite($bycathndl, "<li><a href='#$cat'>$cat ($artcount)</a></li>\n");
			}
			unset($arts);

			fwrite($bycathndl, "</ul>\n");
		}
		unset($cats);

		fwrite($bycathndl, "</ul></div>\n");

		// Write the changes
		if (! empty($prevclean)) {
			fwrite($bycathndl, "<a name='Changes since last update'></a><h2>Changes since last update</h2>\n");

			$newarts = array_diff_key($curclean, $prevclean);
			$artcount = count($newarts);
			fwrite($bycathndl, "<a name='New articles'></a><h3>New articles ($artcount)</h3>\n");
			fwrite($bycathndl, "<table class='wikitable tablesorter'><thead><tr><th>Article</th><th>Importance</th><th>Class</th>
				<th class='unsortable'>Issues</th></tr></thead><tbody>\n
				");

			foreach ($newarts as $title => &$art) {
				$consolidated = $this->_consolidateCats($art[self::KEY_ISSUES], true);
				$artcats = implode(', ', $consolidated['issues']);
				$clssort = CreateTables::$CLASSES[$art[self::KEY_CLS]];
				$impsort = CreateTables::$IMPORTANCES[$art[self::KEY_IMP]];
				fwrite($bycathndl, "<tr><td><a href=\"$wikiprefix" . urlencode(str_replace(' ', '_', $title)) . "\">" .
					htmlentities($title, ENT_COMPAT, 'UTF-8') . "</a></td>
					<td data-sort-value='{$impsort}'>{$art[self::KEY_IMP]}</td>
					<td data-sort-value='{$clssort}'>{$art[self::KEY_CLS]}</td>
					<td>{$artcats}</td></tr>\n");
			}
			unset($art);

			fwrite($bycathndl, "</tbody></table>\n");

			$resarts = array_diff_key($prevclean, $curclean);
			$artcount = count($resarts);
			fwrite($bycathndl, "<a name='Resolved articles'></a><h3>Resolved articles ($artcount)</h3>\n");
			fwrite($bycathndl, "<table class='wikitable tablesorter'><thead><tr><th>Article</th><th>Importance</th><th>Class</th>
					<th class='unsortable'>Issues</th></tr></thead><tbody>\n
					");

			foreach ($resarts as $title => &$fields) {
				fwrite($bycathndl, "<tr><td><a href=\"$wikiprefix" . urlencode(str_replace(' ', '_', $title)) . "\">" .
					htmlentities($title, ENT_COMPAT, 'UTF-8') . "</a></td>
					<td>{$fields[0]}</td><td>{$fields[1]}</td><td>{$fields[4]}</td></tr>\n");
			}
			unset($fields);

			fwrite($bycathndl, "</tbody></table>\n");
		}

		// Write the cats
		foreach ($catgroups as $catgroup => &$cats) {
			fwrite($bycathndl, "<a name='$catgroup'></a><h2>$catgroup</h2>\n");

			foreach ($cats as $cat => &$arts) {
				$catlen = strlen($cat);
				$artcount = count($arts);
				if (! isset($anchors[$cat])) fwrite($bycathndl, "<a name='$cat'></a>");
				else foreach ($anchors[$cat] as $anchorname) fwrite($bycathndl, "<a name='$anchorname'></a>");
				fwrite($bycathndl, "<h3>$cat ($artcount)</h3>\n");
				fwrite($bycathndl, "<table class='wikitable tablesorter'><thead><tr><th>Article</th><th>Importance</th><th>Class</th><th>Count</th>
					<th>Oldest</th><th class='unsortable'>Issues</th></tr></thead><tbody>\n");

				ksort($arts);

				foreach ($arts as $title => $dummy) {
					//Strip the current cat prefix to make page smaller
					$art = $titles[$title];
					$keycats = $art[self::KEY_CATS];
					foreach ($keycats as $key => $value) {
						if (strpos($value, $cat) === 0) {
							if (strlen($value) > $catlen) $keycats[$key] = '...' . substr($value, $catlen);
							else unset ($keycats[$key]);
						}
					}
					$artcats = implode(', ', $keycats);

					fwrite($bycathndl, "<tr><td><a href=\"$wikiprefix" . urlencode(str_replace(' ', '_', $title)) . "\">" .
						htmlentities($title, ENT_COMPAT, 'UTF-8') . "</a></td>
						<td data-sort-value='{$art[self::KEY_IMPSORT]}'>{$art[self::KEY_IMP]}</td>
						<td data-sort-value='{$art[self::KEY_CLSSORT]}'>{$art[self::KEY_CLS]}</td><td align='right'>{$art[self::KEY_ICOUNT]}</td>
						<td data-sort-value='{$art[self::KEY_EARLIESTSORT]}'>{$art[self::KEY_EARLIEST]}</td><td>{$artcats}</td></tr>\n");
				}

				fwrite($bycathndl, "</tbody></table>\n");
			}
			unset($arts);
		}
		unset($cats);

		fwrite($bycathndl, "<br />Generated by <a href='https://en.wikipedia.org/wiki/User:CleanupWorklistBot' class='novisited'>CleanupWorklistBot</a></body></html>");
		fclose($bycathndl);

        //
		// Write the history list
		//

		$sth = $dbh_tools->prepare("INSERT INTO history VALUES (?, ?, $project_pages, $cleanup_pages, $issue_count, $added_pages, $removed_pages)");
		$histdate = sprintf('%d-%02d-%02d', $this->asof_date['year'], $this->asof_date['mon'], $this->asof_date['mday']);
		$sth->execute(array($project, $histdate));

		$histpath = $this->outputdir . 'history' . DIRECTORY_SEPARATOR . $filesafe_project . '.html';
		$histhndl = fopen($histpath, 'wb');
		fwrite($histhndl, "<!DOCTYPE html>
		<html><head>
		<meta http-equiv='Content-type' content='text/html;charset=UTF-8' />
		<title>Cleanup history for {$wikiproject}{$project_title}</title>
		<link rel='stylesheet' type='text/css' href='../../css/cwb.css' />
		</head><body>
		<p>Cleanup history for <a href=\"$projecturl\">{$wikiproject}{$project_title}</a>.</p>
		<p>Listings: <a href=\"$alphaurl\">Alphabetic<a> <b>&bull;</b> <a href=\"$bycaturl\">By category</a> <b>&bull;</b> <a href=\"$csvurl\">CSV</a> <b>&bull;</b> History</p>
		<table class='wikitable'><thead><tr><th>Date</th><th>Total articles</th><th>Cleanup articles</th><th>Cleanup issues</th><th>New articles</th><th>Resolved articles</th></tr></thead><tbody>\n
		");

		$sth = $dbh_tools->prepare("SELECT * FROM history WHERE project = ? ORDER BY time");
		$sth->execute(array($project));

		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			fwrite($histhndl, "<tr><td>{$row['time']}</td><td>{$row['total_articles']}</td><td>{$row['cleanup_articles']}</td><td>{$row['issues']}</td><td>{$row['added_articles']}</td><td>{$row['removed_articles']}</td></tr>\n");
		}

		fwrite($histhndl, "</tbody></table>Generated by <a href='https://en.wikipedia.org/wiki/User:CleanupWorklistBot' class='novisited'>CleanupWorklistBot</a></body></html>");
		fclose($histhndl);

		//Finished successfully
		rename($tmpcsvpath, $csvpath);

		$wikiproject = (($isWikiProject) ? 'WikiProject_' : '') . $project;

		$sth = $dbh_tools->prepare("DELETE FROM project WHERE `name` = ?");
		$sth->execute(array($wikiproject));
		$sth = $dbh_tools->prepare("INSERT INTO project VALUES (?, ?)");
		$sth->execute(array($wikiproject, 1));

		$dbh_tools = null;

		return true;
	}

	/**
	 * Consolidate issues that have multiple dates.
	 * Determine the earliest date.
	 *
	 * @param array $cat_ids index into $this->categories keys = 'title', 'mth', 'yr'
	 * @param bool $shortnames return short category names
	 * @return array Earliest date 'earliest', 'issues', 'earliestsort' Consolidated issues, one string per category
	 */
	function _consolidateCats(&$cat_ids, $shortnames = false)
	{
		$results = array();
		$earliestyear = 9999;
		$earliestmonth = 99;

		foreach ($cat_ids as $cat_id) {
			$cat = str_replace('_', ' ', $this->categories[$cat_id][self::KEY_TITLE]);
			if ($shortnames)
			{
				if (isset(Categories::$CATEGORIES[$cat]['display'])) $cat = Categories::$CATEGORIES[$cat]['display'];
				elseif (isset(Categories::$SHORTCATS[$cat])) $cat = Categories::$SHORTCATS[$cat];
			}

			if (! isset($results[$cat])) $results[$cat] = array();

			if ($this->categories[$cat_id][self::KEY_YR] != null) {
				$intyear = (int)$this->categories[$cat_id][self::KEY_YR];
				$month = '';

				if ($this->categories[$cat_id][self::KEY_MTH] != null) {
					$intmonth = (int)$this->categories[$cat_id][self::KEY_MTH];
					$month = self::$MONTHS[$intmonth] . ' ';

					if ($intyear == $earliestyear && $intmonth < $earliestmonth) {
						$earliestmonth = $intmonth;
					} elseif ($intyear < $earliestyear) {
						$earliestmonth = $intmonth;
						$earliestyear = $intyear;
					}
				} elseif ($intyear < $earliestyear) {
					$earliestyear = $intyear;
					$earliestmonth = 99;
				}

				$results[$cat][] = $month . $this->categories[$cat_id][self::KEY_YR];
			}
		}

		$cats = array();

		foreach ($results as $cat => $dates) {
			$dates = implode(', ', $dates);
			if (! empty($dates)) $cat .= " ($dates)";
			$cats[] = $cat;
		}

		$earliestdate = '';
		$earliestsort = 999999;

		if ($earliestyear != 9999) {
			$month = '';
			$monthsort = 99;
			if ($earliestmonth != 99) {
				$month = self::$MONTHS[$earliestmonth] . ' ';
				$monthsort = sprintf('%02d', $earliestmonth);
			}
			$earliestdate = $month . $earliestyear;
			$earliestsort = $earliestyear . $monthsort;
		}

		return array('earliest' => $earliestdate, 'issues' => $cats, 'earliestsort' => $earliestsort);
	}
}