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

namespace com_brucemyers\PageTools;

use PDO;
use PDOException;
use com_brucemyers\Util\Config;
use com_brucemyers\MediaWiki\MediaWiki;


class UIHelper
{
	protected $serviceMgr;

	public function __construct()
	{
		$this->serviceMgr = new ServiceManager();
	}

	/**
	 * Get the page tools.
	 *
	 * @param array $params keys = wiki, page
	 * @return array keys = abstract (string - html),
	 * 	categories (array, key = category, value = hidden(bool)),
	 * 	wikidata (array of WikidataItem),
	 *  wikidata_exact_match (bool),
	 *  pagetext (string)
	 *  lang (string)
	 *  domain (string)
	 *  pagename (string)
	 */
	public function getResults($params)
	{
		$results = array();
		$pagename = str_replace(' ', '_', ucfirst(trim($params['page'])));
		$wiki = $params['wiki'];

		if (preg_match('!([a-z]{2,3})wiki!', $params['wiki'], $matches)) {
			$lang = $matches[1];
			$domain = $lang . '.wikipedia.org';
		} elseif ($params['wiki'] == 'wikidata') {
			$lang = 'en';
			if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && preg_match('!([a-zA-Z]+)!', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $matches)) {
				$lang = strtolower($matches[1]);
			}
			$domain = 'www.wikidata.org';
//		} elseif ($params['wiki'] == 'commons') {
//			$domain = 'commons.wikimedia.org';
		} else {
			$results['errors'][] = 'Wiki not supported - contact author';
			return $results;
		}

		$results['lang'] = $lang;
		$results['domain'] = $domain;

		if ($params['wiki'] == 'wikidata') {
        	$wikidatawiki = $this->serviceMgr->getWikidataWiki();
        	$results['wikidata'] = $wikidatawiki->getItemsNoCache(ucfirst(trim($params['page'])));

        	if (empty($results['wikidata'])) {
        		$results['errors'][] = 'Page not found';
        		return $results;
        	}

        	$results['wikidata_exact_match'] = true;
        	$results['categories'] = array();
        	$results['pagetext'] = '';
        	$label = $results['wikidata'][0]->getLabelDescription('label', $lang);
        	$description = $results['wikidata'][0]->getLabelDescription('description', $lang);

        	if ($label != '' && $description != '') $results['abstract'] = "$label - $description";
        	else if ($label != '') $results['abstract'] = $label;
        	else if ($description != '') $results['abstract'] = $description;
        	if (empty($results['abstract'])) $results['abstract'] = $params['page'];

        	$site = $results['wikidata'][0]->getSiteLink("{$lang}wiki");

        	if (! empty($site)) {
        		$results['pagename'] = $site['title'];
        		$pagename = str_replace(' ', '_', $results['pagename']);
        		preg_match('!([a-z]{2,3})wiki!', $site['site'], $matches);
        		$sitelang = $matches[1];
        		$results['domain'] = $sitelang . '.wikipedia.org';
        		$wiki = "{$sitelang}wiki";
        	}

        	if (empty($site) || $sitelang != $lang) return $results;
		}

		$domain = $results['domain'];

		$mediawiki = $this->serviceMgr->getMediaWiki($domain);

		// Get the page text
		$pagetext = $mediawiki->getpage($pagename);
		if (! $pagetext) {
			$results['errors'][] = 'Page not found';
			return $results;
		}

		$results['pagetext'] = $pagetext;

		// Get the abstract
		$value = $mediawiki->getPageLead($pagename);
		$x = 1;
		while (++$x <= 5 and strlen($value) < 100) {
			$value = $mediawiki->getPageLead($pagename, $x);
		}
		if (strlen($value) < 100) $value = $mediawiki->getPageLead($pagename, 0, 100);
		if (empty($value)) $value = str_replace('_', ' ', $pagename);

		$results['abstract'] = $value;

		// Get the categories
		$results['categories'] = array();
	    $catparams = array(
            'titles' => $pagename,
            'clprop' => 'hidden',
            'cllimit' => Config::get(MediaWiki::WIKIPAGEINCREMENT)
        );

        $continue = array('continue' => '');

        while ($continue !== false) {
            $clparams = array_merge($catparams, $continue);

            $ret = $mediawiki->getProp('categories', $clparams);

            if (isset($ret['error'])) throw new Exception('PageTools/UIHelper failed ' . $ret['error']);
            if (isset($ret['continue'])) $continue = $ret['continue'];
            else $continue = false;

	        if (! empty($ret['query']['pages'])) {
	        	$page = reset($ret['query']['pages']);
	        	if (isset($page['categories'])) {
		        	foreach ($page['categories'] as $cat) {
		        		$hidden = isset($cat['hidden']);
		        		$cattitle = substr($cat['title'], 9);
		        		$results['categories'][$cattitle] = $hidden;
		        	}
	        	}
	        }
        }

        // Get the wikidata match and/or likely matches
        $dbh_wikidata = $this->serviceMgr->getDBConnection('wikidatawiki');

		$pagename = str_replace('_', ' ', $pagename);
        $sql = "SELECT ips_item_id FROM wb_items_per_site WHERE ips_site_id = ? AND ips_site_page = ?";
        $sth = $dbh_wikidata->prepare($sql);
        $sth->bindValue(1, $wiki);
        $sth->bindValue(2, str_replace('_', ' ', $pagename));
        $sth->execute();

        $wikidata_ids = array();

        if ($row = $sth->fetch(PDO::FETCH_NUM)) {
        	$id = "Q{$row[0]}";
        	$wikidata_ids[$id] = array();
        	$results['wikidata_exact_match'] = true;
        } else {
        	$results['wikidata_exact_match'] = false;
        	$temppage = str_replace('_', ' ', $pagename);
        	// Strip qualifier
        	$temppage = preg_replace('! \([^\)]+\)!', '', $temppage);

        	// Look for an exact match
			$temppage2 = $dbh_wikidata->quote($temppage);
        	$exactsql = "SELECT ips_item_id, ips_site_id, 0 AS priority FROM wb_items_per_site WHERE ips_site_page = $temppage2";

        	// Only use first and last words for partial match
        	$pageparts = explode(' ', $temppage);
        	if (count($pageparts) > 1) {
        		$temppage = $pageparts[0] . ' %' . $pageparts[count($pageparts) - 1];
        	}

			$temppage = $dbh_wikidata->quote("$temppage%"); // allow qualifier

        	$sql = "($exactsql) UNION (SELECT ips_item_id, ips_site_id, 1 AS priority FROM wb_items_per_site
        		WHERE ips_site_page LIKE $temppage) ORDER BY priority LIMIT 10";
        	$sth = $dbh_wikidata->prepare($sql);
        	$sth->execute();
        	$sth->setFetchMode(PDO::FETCH_NUM);

        	while ($row = $sth->fetch()) {
        		$id = "Q{$row[0]}";
        		$site = $row[1];
        		if (! isset($wikidata_ids[$id])) $wikidata_ids[$id] = array();
        		$wikidata_ids[$id][] = $site;
        	}
        }

        $sth->closeCursor();
        $sth = null;

        // Retrieve the current wikidata revisions
        $wikidatawiki = $this->serviceMgr->getWikidataWiki();
        $results['wikidata'] = $wikidatawiki->getItemsNoCache(array_keys($wikidata_ids));

		return $results;
	}

	/**
	 * Get replication lag.
	 *
	 * @param unknown $wikiname
	 * @return array keys = replag, lastupdate
	 */
	public function getReplicationLag($wikiname)
	{
		$return = array('replag' => 'Error retrieving lag', 'lastupdate' => '');

		try {
      		$dbh_wiki = $this->serviceMgr->getDBConnection($wikiname);

			$sth = $dbh_wiki->query('SELECT MAX(rc_timestamp), UNIX_TIMESTAMP() - UNIX_TIMESTAMP(MAX(rc_timestamp)) FROM recentchanges');

			if ($row = $sth->fetch(PDO::FETCH_NUM)) {
				$lastupdate = date($row[0]);
				$seconds = (int)$row[1];

				$days = floor($seconds / 86400);
				$seconds -= $days * 86400;
				$hours = floor($seconds / 3600);
				$seconds -= $hours * 3600;
				$minutes = floor($seconds / 60);
				$seconds -= $minutes * 60;

				$replag = '';
				if ($days > 0) {
					$replag .= " $days ";
					$replag .= ($days == 1) ? 'day' : 'days';
				}

				if ($days > 0 || $hours > 0) {
					$replag .= " $hours ";
					$replag .= ($hours == 1) ? 'hour' : 'hours';
				}

				if ($days > 0 || $hours > 0 || $minutes > 0) {
					$replag .= " $minutes ";
					$replag .= ($minutes == 1) ? 'minute' : 'minutes';
				}

//				$replag .= " $seconds ";
//				$replag .= ($seconds == 1) ? 'second' : 'seconds';

				$replag = trim($replag);

				$return = array('replag' => $replag, 'lastupdate' => substr($lastupdate, 0, 4) . '-' . substr($lastupdate, 4 , 2) .
						'-' . substr($lastupdate, 6, 2) . ' ' . substr($lastupdate, 8, 2) . ':' . substr($lastupdate, 10, 2) . ':' .
						substr($lastupdate, 12, 2));
			}
		} catch (PDOException $ex) {
		}

		return $return;
	}
}