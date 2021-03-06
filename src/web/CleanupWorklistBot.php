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

use com_brucemyers\Util\Config;
use com_brucemyers\CleanupWorklistBot\CleanupWorklistBot;
use com_brucemyers\CleanupWorklistBot\CreateTables;
use com_brucemyers\CleanupWorklistBot\ProjectPages;

$webdir = dirname(__FILE__);
// Marker so include files can tell if they are called directly.
$GLOBALS['included'] = true;
$GLOBALS['botname'] = 'CleanupWorklistBot';

require $webdir . DIRECTORY_SEPARATOR . 'bootstrap.php';

$action = @ $_REQUEST['action'];
$project = @ $_REQUEST['project'];
$category = @ $_REQUEST['category'];

switch ($action) {
	case 'test':
		cat_test($project, $category);
		break;

	default:
		cat_display($project, $category);
		break;
}

/**
 * Display the input page with optional results
 *
 * @param $project string Project name
 * @param $category string (Optional) Category to override default
 * @param $results string (Optional) Test results to display
 */
function cat_display($project, $category, $results = null)
{
    ?>
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
    <html xmlns="http://www.w3.org/1999/xhtml">
    <head>
	    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	    <meta name="robots" content="noindex, nofollow" />
	    <title>CleanupWorklistBot Category Test</title>
	    <style>
	        li {
                margin-bottom: 5px;
            }
	    </style>
	</head>
	<body>
        <h2>CleanupWorklistBot Category Test</h2>
        <form action="CleanupWorklistBot.php" ><table class="form">
        <tr><td><b>Project name</b> <input name="action" type="hidden" value="test" /><input name="project" type="text" size="20" id="testfield1" value="<?php echo $project ?>" /> ex. Pinball (do not include WikiProject prefix)</td></tr>
        <tr><td><b>Category override (optional)</b> <input name="category" type="text" size="25" value="<?php echo $category ?>" /> ex. amphibian_and_reptile</td></tr>
        <tr><td><input type="submit" value="Submit" /></td></tr>
        </table></form>

        <script type="text/javascript">
            if (document.getElementById) {
                document.getElementById('testfield1').focus();
            }
        </script>
    <?php

    if (! empty($results)) {
        echo '<h2>Results</h2>';
        echo $results;
    }

    ?></body></html><?php
}

/**
 * Test category search
 *
 * @param $project string Project name
 * @param $category_override string (Optional) Category to override default
 */
function cat_test($project, $category_override)
{
    if (empty($project)) {
        cat_display($project, $category_override);
        return;
    }

    $project = str_replace(' ', '_', $project);
    if (! empty($category_override)) $category_override = str_replace(' ', '_', $category_override);

    $category = $category_override;
    if (empty($category)) $category = $project;

    $result = _test_category($category);

    // Try lowercase project name
    if (empty($category_override) && (empty($result['project_members']) || ! $result['found_class'])) {
    	$test_category = strtolower($project);
    	$result2 = _test_category($test_category);

    	if (! empty($result2['project_members']) && (empty($result['project_members']) || $result2['found_class'])) {
			$result = $result2;
			$category_override = $test_category;
    	}
    }

    $output = '';

	if (! empty($result['project_members'])) {
		$project_html = htmlspecialchars($project);
		$output .= "Master project list configuration line: WikiProject_{$project_html}";
		if (! empty($category_override)) $output .= ' => ' . htmlspecialchars($category_override);
		$output .= '<br />';
		$project_cat = urlencode($result['project_cat']);
		$project_members = htmlspecialchars($result['project_members']);
		$output .= "Project articles category: <a href='https://en.wikipedia.org/wiki/Category:$project_cat'>$project_members</a><br />";
		$output .= 'Project class categories found: ' . (($result['found_class']) ? 'Yes' : 'No') . '<br />';
		$output .= 'Project importance categories found: ' . (($result['found_importance']) ? 'Yes' : 'No') . '<br />';
	} else {
		$output .= 'Project articles category: <span style="font-style: italic;">Not found</span><br />';
	}

    cat_display($project, $category_override, $output);
}

/**
 * Tests a category.
 *
 * @param string $category
 * @return multitype:string boolean
 */
function _test_category($category)
{
	$result = array('project_members' => '', 'project_cat' => '', 'found_importance' => false, 'found_class' => false);
	$enwiki_host = Config::get(CleanupWorklistBot::ENWIKI_HOST);
	$user = Config::get(CleanupWorklistBot::LABSDB_USERNAME);
	$pass = Config::get(CleanupWorklistBot::LABSDB_PASSWORD);
	$dbh_enwiki = new PDO("mysql:host=$enwiki_host;dbname=enwiki_p;charset=utf8", $user, $pass);
	$dbh_enwiki->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$project_members = '';

	// category - x articles by quality (subcats)
	$sth = $dbh_enwiki->prepare(ProjectPages::SQL_Articles_by_quality);
	$ucfcategory = ucfirst($category);
	$param = "{$ucfcategory}_articles_by_quality";
	$sth->bindParam(1, $param);
	$sth->execute();

	if ($sth->fetch(PDO::FETCH_ASSOC)) {
		$project_members = "$param (child categories)";
		$project_cat = $param;
	}
	$sth->closeCursor();

	if (empty($project_members)) {
		// category - WikiProject x articles
		$sth = $dbh_enwiki->prepare(ProjectPages::SQL_WikiProject_articles);
		$param = "WikiProject_{$category}_articles";
		$sth->bindParam(1, $param);
		$sth->execute();

		if ($sth->fetch(PDO::FETCH_ASSOC)) {
			$project_members = $param;
			$project_cat = $param;
		}
		$sth->closeCursor();
	}

	if (empty($project_members)) {
		// category - x (talk namespace)
		$sth = $dbh_enwiki->prepare(ProjectPages::SQL_Category_talk);
		$param = $category;
		$sth->bindParam(1, $param);
		$sth->execute();

		if ($sth->fetch(PDO::FETCH_ASSOC)) {
			$project_members = "$param (talk namespace)";
			$project_cat = $param;
		}
		$sth->closeCursor();
	}

	if (empty($project_members)) {
		// category - x (article namespace)
		$sth = $dbh_enwiki->prepare(ProjectPages::SQL_Category_article);
		$param = $category;
		$sth->bindParam(1, $param);
		$sth->execute();

		if ($sth->fetch(PDO::FETCH_ASSOC)) {
			$project_members = "$param (article namespace)";
			$project_cat = $param;
		}
		$sth->closeCursor();
	}


	if (! empty($project_members)) {
		$result['project_members'] = $project_members;
		$result['project_cat'] = $project_cat;

		// Check importance categories
		$found_importance = false;

		foreach (array_keys(CreateTables::$IMPORTANCES) as $importance) {
			$sth = $dbh_enwiki->prepare(ProjectPages::SQL_Importance . ' LIMIT 1');
			$sth->bindValue(1, "{$importance}-importance_{$category}_articles");
			$sth->execute();

			if ($sth->fetch(PDO::FETCH_ASSOC)) {
				$found_importance = true;
				$sth->closeCursor();
				break;
			}

			$sth->closeCursor();
		}

		$result['found_importance'] = $found_importance;

		// Check class categories
		$found_class = false;

		foreach (array_keys(CreateTables::$CLASSES) as $class) {
			if ($class == 'Unassessed')
				$theclass = "{$class}_{$category}_articles";
			else
				$theclass = "{$class}-Class_{$category}_articles";

			$sth = $dbh_enwiki->prepare(ProjectPages::SQL_Class . ' LIMIT 1');
			$sth->bindValue(1, $theclass);
			$sth->execute();

			if ($sth->fetch(PDO::FETCH_ASSOC)) {
				$found_class = true;
				$sth->closeCursor();
				break;
			}

			$sth->closeCursor();
		}

		$result['found_class'] = $found_class;
	}

	return $result;
}