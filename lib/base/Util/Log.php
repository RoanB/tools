<?php
/**
 * Util class
 *
 * Contains general purpose utilities
 *
 * @author David Vandemaele <david@tigron.be>
 */

class Util_Log {
	/**
	 * Generate a URL for logs which is safe (passwords obfuscated)
	 *
	 * @access public
	 * @param array $get
	 * @param array $server
	 * @return string
	 */
	public static function safe_url($get, $server) {
		$url = $server['REQUEST_SCHEME'] . '://' . $server['HTTP_HOST'] . $server['REQUEST_URI'];
		if (strpos($url, '?') !== false) {
			$url = substr($url, 0, strpos($url, '?'));
		}
		if (count($get) > 0) {
			$url .= '?';
		}
		foreach ($get as $key => $value) {
			if (in_array(strtolower($key), [ 'password', 'passwd', 'pwd' ])) {
				$url .= $key . '=********&';
			} else {
				$url .= $key . '=' . $value . '&';
			}
		}
		if (\Util::endsWith($url, '&')) {
			$url = substr($url, 0, strlen($url) - 1);
		}
		return $url;
	}

	/**
	 * API security
	 * Log openapi security events
	 *
	 * @access public
	 * @param string $string
	 */
	public static function openapi_security($string): void {
		file_put_contents(\Skeleton\Core\Config::get()->tmp_dir . '/log/openapi_security.log', '[' . date('d/m/Y H:i:s') . '] - ' . $string . "\n", FILE_APPEND);
	}

	/**
	 * Log request log
	 *
	 * @param string $string
	 */
	public static function request($string) {
		file_put_contents(\Skeleton\Core\Config::get()->tmp_dir . '/log/request.log', '[' . date('d/m/Y H:i:s') . '] ' . $string . "\n", FILE_APPEND);
	}

	/**
	 * Log api
	 *
	 * @param Array $string
	 */
	public static function api($array) {
		$string = '[' . date('d/m/Y H:i:s') . "] \n\n";
		$string .= json_encode($array, JSON_PRETTY_PRINT) . "\n\n";
		$string .= "----------------------------------------------------------" . "\n";
		file_put_contents(\Skeleton\Core\Config::get()->tmp_path . '/log/openapi.log', $string . "\n", FILE_APPEND);
	}

	/**
	 * Log logins
	 *
	 * @param string $string
	 */
	public static function login($string) {
		file_put_contents(\Skeleton\Core\Config::get()->tmp_dir . '/log/login.log', '[' . date('d/m/Y H:i:s') . '] ' . $string . "\n", FILE_APPEND);
	}

	/**
	 * Log transaction
	 *
	 * @param string $string
	 */
	public static function transaction($string) {
		file_put_contents(\Skeleton\Core\Config::get()->tmp_dir . '/log/transaction.log', $string, FILE_APPEND);
	}

	/**
	 * Log queries
	 *
	 * @param string $string
	 */
	public static function query($string) {
		file_put_contents(\Skeleton\Core\Config::get()->tmp_dir . '/log/query.log', $string, FILE_APPEND);
	}
}
