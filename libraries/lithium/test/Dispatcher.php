<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2009, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace lithium\test;

use \lithium\util\Set;
use \lithium\util\Inflector;
use \lithium\core\Libraries;

/**
 * The Lithium Test Dispatcher
 *
 * This Dispatcher is used exclusively for the purpose of running, organizing and compiling
 * statistics for the built-in Lithium test suite.
 */
class Dispatcher extends \lithium\core\StaticObject {

	/**
	 * Composed classes used by the Dispatcher.
	 *
	 * @var array Key/value array of short identifier for the fully-namespaced
	 *            class.
	 */
	protected static $_classes = array(
		'group' => '\lithium\test\Group'
	);

	/**
	 * Runs a test group or a specific test file based on the passed
	 * parameters.
	 *
	 * @param string $group If set, this test group is run. If not set, a group test may
	 *        also be run by passing the 'group' option to the $options parameter.
	 * @param array  $options Options array for the test run. Valid options are:
	 *	      - 'base': Base path of where test cases are located.
	 *                   If not set, the value for 'path' is taken.
	 *		  - 'case': The fully namespaced test case to be run.
	 *        - 'group': The fully namespaced test group to be run.
	 *		  - 'filters': An array of filters that the test output should be run through.
	 *		  - 'path': Where the test cases are located.
	 *				     Defaults to LITHIUM_LIBRARY_PATH . '/lithium/tests/cases'
	 * @return array A compactified array of the title, an array of the results, as well
	 *         as an additional array of the restults after the $options['filters']
	 *         have been applied.
	 */
	public static function run($group = null, $options = array()) {
		$defaults = array(
			'base' => null,
			'case' => null,
			'group' => null,
			'filters' => array(),
			'path' => LITHIUM_LIBRARY_PATH . '/lithium/tests/cases',
		);
		$options += $defaults;

		if (is_null($options['base'])) {
			$options['base'] = $options['path'];
		}
		$group = $group ?: static::_group($options);

		if (!$group) {
			return null;
		}
		$title = $options['case'] ?: $options['group'];
		list($results, $filters) = static::_execute($group, Set::normalize($options['filters']));


		return compact('title', 'results', 'filters');
	}

	/**
	 * Generates the sidebar menu on the test summary page.
	 *
	 * @param string $type The format that the generated output should have.
	 *        Valid options are 'html' and 'txt'.
	 * @return string Formatted menu
	 */
	public static function menu($type) {
		$classes = Libraries::locate('tests');
		$data = array();

		$assign = function(&$data, $class, $i = 0) use (&$assign) {
			ksort($data);
			isset($data[$class[$i]]) ?: $data[] = $class[$i];
			$end = (count($class) <= $i + 1);

			if (!$end && ($offset = array_search($class[$i], $data)) !== false) {
				$data[$class[$i]] = array();
				unset($data[$offset]);
			}
			$end ?: $assign($data[$class[$i]], $class, $i + 1);
		};

		foreach ($classes as $class) {
			$assign($data, explode('\\', str_replace('\tests\cases', '', $class)));
		}
		ksort($data);

		$format = function($test) use ($type) {
			if ($type == 'html') {
				if ($test == 'group') {
					return '<li><a href="?group=%1$s">%2$s</a>%3$s</li>';
				}
				if ($test == 'case') {
					return '<li><a href="?case=%2$s\%1$s">%1$s</a></li>';
				}
			}

			if ($type == 'txt') {
				if ($test == 'group') {
					return "-group %1$s\n%2$s\n";
				}
				if ($test == 'case') {
					return "-case %1$s\n";
				}
			}

			if ($type == 'html') {
				return sprintf('<ul>%s</ul>', $test);
			}
			if ($type == 'txt') {
				return sprintf("\n%s\n", $test);
			}
		};
		$result = null;

		$menu = function ($data, $parent = null) use (&$menu, $format, $result) {
			foreach ($data as $key => $row) {
				if (is_array($row) && is_string($key)) {
					$key = strtolower($key);
					$next = $parent . '\\' . $key;
					$result .= sprintf(
						$format('group'), $next, $key, $menu($row, $next)
					);
				} else {
					$next = $parent . '\\' . $key;
					$result .= sprintf($format('case'), $row, $parent);
				}
			}
			return $format($result);
		};
		foreach ($data as $library => $tests) {
			$group = "\\{$library}\\tests\cases";
			$result .= $format(sprintf(
				$format('group'), $group, $library, $menu($tests, $group)
			));
		}
		return $result;
	}

	/**
	 * Processes the aggregated results from the test cases and compiles some
	 * basic statistics.
	 *
	 * @param  array $results An array of results as returned by Dispatcher::run().
	 * @return array Array of results. Data includes aggregated values for
	 *         passes, fails, exceptions, errors, and assertions.
	 */
	public static function process($results) {
		return array_reduce((array)$results, function($stats, $result) {
			$stats = (array)$stats + array(
				'asserts' => 0,
				'passes' => array(),
				'fails' => array(),
				'exceptions' => array(),
				'errors' => array()
			);
			$result = empty($result[0]) ? array($result) : $result;

			foreach ($result as $response) {
				if (empty($response['result'])) {
					continue;
				}
				$result = $response['result'];

				if (in_array($result, array('fail', 'exception'))) {
					$stats['errors'][] = $response;
				}
				unset($response['file'], $response['result']);

				if (in_array($result, array('pass', 'fail'))) {
					$stats['asserts']++;
				}
				if (in_array($result, array('pass', 'fail', 'exception'))) {
					$stats[Inflector::pluralize($result)][] = $response;
				}
			}
			return $stats;
		});
	}

	/**
	 * Creates the test group class based on either the passed test case or the
	 * passed test group.
	 *
	 * @param array $options Options array passed from Dispatcher::run(). Should contain
	 *        one of 'case' or 'group' keys.
	 * @return object Group object constructed with the test case or group passed in $options.
	 * @see \lithium\test\Dispatcher::$_classes
	 */
	protected static function _group($options) {
		if (!empty($options['case'])) {
			return new static::$_classes['group'](array('items' => array(new $options['case'])));
		} elseif (isset($options['group'])) {
			return new static::$_classes['group'](array('items' => (array)$options['group']));
		}
	}

	/**
	 * Runs the given tests through the applicable filters.
	 *
	 * @param object $group The test Group object, which contains the test cases.
	 * @param array $filters The filters to be applied to the test cases.
	 * @return array An array of two elements, the first being the results of the test
	 *         run, the second being the results of the filtered test run.
	 */
	protected static function _execute($group, $filters) {
		$tests = $group->tests();
		$filterResults = array();

		foreach ($filters as $filter => $options) {
			$options = isset($options['apply']) ? $options['apply'] : array();
			$tests = $filter::apply($tests, $options);
		}
		$results = $tests->run();

		foreach ($filters as $filter => $options) {
			$options = isset($options['analyze']) ? $options['analyze'] : array();
			$filterResults[$filter] = $filter::analyze($results, $options);
		}
		return array($results, $filterResults);
	}
}

?>