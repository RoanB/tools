<?php
/**
 * Util class
 *
 * Contains general purpose utilities
 *
 * @package %%PACKAGE%%
 * @author Christophe Gosiau <christophe@tigron.be>
 * @author Gerry Demaret <gerry@tigron.be>
 * @version $Id$
 */

class Util {
	/**
	 * Fetches the mime type for a certain file
	 *
	 * @param string $file The path to the file
	 * @return string $mime_type
	 */
    public static function mime_type($file)  {
		$handle = finfo_open(FILEINFO_MIME);
		$mime_type = finfo_file($handle,$file);

		if (strpos($mime_type, ';')) {
			$mime_type = preg_replace('/;.*/', ' ', $mime_type);
		}

		return trim($mime_type);
    }

	/**
	 * Sanitize filenames
	 *
	 * @access public
	 * @param string $name
	 * @return string $name
	 */
	public static function sanitize_filename($name) {
		$special_chars = array ('#','$','%','^','&','*','!','~','‘','"','’','\'','=','?','/','[',']','(',')','|','<','>',';','\\',',','+');
		$name = preg_replace('/^[.]*/','',$name); // remove leading dots
		$name = preg_replace('/[.]*$/','',$name); // remove trailing dots
		$name = str_replace($special_chars, '', $name);// remove special characters
		$name = str_replace(' ','_',$name); // replace spaces with _

		$name_array = explode('.', $name);

		if (count($name_array) > 1) {
			$extension = array_pop($name_array);
		} else {
			$extension = null;
		}

		$name = implode('.', $name_array);
		$name = substr($name, 0, 50);

		if ($extension != null) {
			$name = $name . '.' . $extension;
		}

		return $name;
	}

	/**
	 * Callback function for sorting objects
	 *
	 * @access public
	 * @param string $property
	 */
	public static function sort_objects($objects, $property, $direction = 'asc', $type = 'auto') {
        @usort($objects, function($a, $b) use ($property, $direction, $type) {
			if (!is_object($property) AND isset($a->$property)) {
				$property1 = $a->$property;
				$property2 = $b->$property;
			} elseif (is_callable([$a, $property])) {
				$property1 = call_user_func_array([$a, $property], []);
				$property2 = call_user_func_array([$b, $property], []);
			} elseif (is_callable($property)) {
				$property1 = $property($a);
				$property2 = $property($b);
			}

			if (is_numeric($property1) AND is_numeric($property2) AND $type == 'auto') {
				$type = 'int';
			}

			if ($type == 'string') {
				$cmp = strcasecmp($property1, $property2);
			} elseif ($type == 'date') {
				if (strtotime($property1) > strtotime($property2)) {
					$cmp = 1;
				} else {
					$cmp = -1;
				}
			} else {
				$cmp = $property1 > $property2 ? 1 : -1;
			}

			if ($direction == 'desc') {
				return -1*$cmp;
			} else {
				return $cmp;
			}
		});

		return $objects;
	}

	/**
	 * Call
	 *
	 * @access public
	 * @param string $method
	 * @param array $arguments
	 */
	public static function __callStatic($method, $arguments) {
		list($classname, $method) = explode('_', $method, 2);
		$class = ucfirst($classname) . '.php';
		require_once __DIR__ . '/Util/' . $class;
		$classname = 'Util_' . $classname;

		if (!method_exists($classname, $method)) {
			throw new Exception('method ' . $method . ' does not exists');
		}

		$result = forward_static_call_array(array($classname, $method), $arguments);
		return $result;
	}
}
