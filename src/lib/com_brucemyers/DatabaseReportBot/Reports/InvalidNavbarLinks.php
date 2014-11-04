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

namespace com_brucemyers\DatabaseReportBot\Reports;

use com_brucemyers\MediaWiki\MediaWiki;
use com_brucemyers\RenderedWiki\RenderedWiki;
use com_brucemyers\Util\TemplateParamParser;
use PDO;

class InvalidNavbarLinks extends DatabaseReport
{
    public function getUsage()
    {
    	return " - Check that Navbar links match parent template name";
    }

	public function getTitle()
	{
		return 'Invalid Navbar links';
	}

	public function getIntro()
	{
		return 'Invalid Navbar links; v-t-e links point to the wrong template; data as of <onlyinclude>%s</onlyinclude>.';
	}

	public function getHeadings()
	{
		return array('Template', 'Invalid name');
	}

	public function getRows(PDO $dbh_wiki, PDO $dbh_tools, MediaWiki $mediawiki, RenderedWiki $renderedwiki)
	{
		$template_types = array(
			'Sidebar' => array(
				'children' => array('Sidebar', 'Sidebar with collapsible lists'),
				'name_param' => 'name',
				'exclude_empty' => array(),
				'exclude_values' => array(
					'navbar' => array('none', 'off')
				),
				'exclude_templates' => array('Politics of Canada/proposed split')
			),

			'Infobox' => array(
				'children' => array('Infobox'),
				'name_param' => 'name',
				'exclude_empty' => array(),
				'exclude_values' => array(),
				'exclude_templates' => array()
			),

			'Infobox3cols' => array(
				'children' => array('Infobox3cols'),
				'name_param' => 'name',
				'exclude_empty' => array(),
				'exclude_values' => array(),
				'exclude_templates' => array()
			),

			'BS-map' => array(
				'children' => array('BS-map'),
				'name_param' => 'navbar',
				'exclude_empty' => array('title'),
				'exclude_values' => array(),
				'exclude_templates' => array('Arbatsko-Pokrovskaya Line','Filyovskaya Line','Kalininskaya Line','Kaluzhsko-Rizhskaya Line')
			),

			'BS-header' => array(
		    	'children' => array('BS-header'),
		    	'name_param' => 2,
		    	'exclude_empty' => array(),
		    	'exclude_values' => array(),
				'exclude_templates' => array()
			),
		    'Navbox' => array( // Must be last because 'MySQL server has gone away' happens after this is run
		    	'children' => array('Navbox', 'Navbox with collapsible groups', 'Navbox with columns', 'Navbox with collapsible sections'),
		    	'name_param' => 'name',
		    	'exclude_empty' => array('title'),
		    	'exclude_values' => array(
		        	'navbar' => array('plain', 'off')
		    	),
				'exclude_templates' => array()
		    )
		);

		$groups = array('linktemplate' => false,
				'groups' => array());

		foreach ($template_types as $type_name => $template_type) {
			$groupname = "{{tlxplain|$type_name|{$template_type['name_param']}&#61;}}";
			echo "==$groupname==\n";

			// Retrieve the target navbars
			$navbar_types = $template_type['children'];

			$sql = "SELECT page_title FROM templatelinks, page " .
				" WHERE tl_from_namespace = 10 AND tl_namespace = 10 AND tl_title = ? " .
				" AND page_namespace = 10 AND page_id = tl_from";
			$sth = $dbh_wiki->prepare($sql);
			$sth->bindValue(1, $type_name);
			$sth->execute();
			$sth->setFetchMode(PDO::FETCH_NUM);
			$titles = array();

			while ($row = $sth->fetch()) {
				$titles[] = 'Template:' . $row[0];
			}

			$sth->closeCursor();

			sort($titles);

			$results = array();

			$mediawiki->cachePages($titles);

			foreach ($titles as $template) {
				echo "$template\n";
				$data = $mediawiki->getPageWithCache($template);

				$parsed_templates = TemplateParamParser::getTemplates($data);

				$template = substr($template, 9);
				$template = str_replace('_', ' ', $template);
				$template = ucfirst($template);

				if (in_array($template, $template_type['exclude_templates'])) continue;

				foreach ($parsed_templates as $parsed_template) {
					if (! in_array($parsed_template['name'], $navbar_types)) continue;
					$params = $parsed_template['params'];
//					print_r($params);

					// Exclude if template name is empty
					$name_param = $template_type['name_param'];
					if (empty($params[$name_param])) continue;

					// Exclude if a param is empty
					foreach ($template_type['exclude_empty'] as $exclude_empty) {
						if (empty($params[$exclude_empty])) continue 2;
					}

					// Exclude if param = value
					foreach ($template_type['exclude_values'] as $value_name => $value_values) {
						if (empty($params[$value_name])) continue;
						foreach ($value_values as $value_value) {
							if ($params[$value_name] == $value_value) continue 3;
						}
					}

		    		if (preg_match('!/(archive|child|doc|drafts|main|more|sandbox|shell|testcase)!i', $template)) continue;

		    		$name = str_replace('_', ' ', $params[$name_param]);
		    		$name = preg_replace('!\s+!', ' ', $name);
		    		$name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
		    		$name = ucfirst($name);

		    		if (strpos($name, 'Template:') === 0) {
		    			$name = ucfirst(ltrim(substr($name, 9)));
		    		}

		    		if (strpos($name, '{') !== false) continue;
		    		if (strpos($name, '<') !== false) continue;

		    		if ($name != $template) $results[] = array("[[Template:$template|$template]]", $name);
				}
			}

			$groups['groups'][$groupname] = $results;
		}

		ksort($groups['groups']);

		return $groups;
	}
}