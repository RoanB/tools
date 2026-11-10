<?php
/**
 * Topo_Filestation_Client
 *
 * Thin client for Synology DSM's FileStation web API, used over HTTPS
 * on ds.buysse.io. The topos area never touches a mount point: listings
 * and downloads of the NAS topos folder go through this client, so the
 * web application can live anywhere the NAS is reachable.
 *
 * Talks to /webapi/entry.cgi with SYNO.API.Auth (session-based login
 * with a restricted, read-only DSM account) and SYNO.FileStation.List /
 * SYNO.FileStation.Download. Credentials come from the application
 * config under 'nas' — gitignored, never stored in code.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Core\Application;

class Topo_Filestation_Client {

	/**
	 * DSM base url, e.g. https://ds.buysse.io
	 *
	 * @var string $base_url
	 */
	private string $base_url;

	/**
	 * DSM account of the restricted tool user
	 *
	 * @var string $account
	 */
	private string $account;

	/**
	 * DSM password of the restricted tool user
	 *
	 * @var string $password
	 */
	private string $password;

	/**
	 * Session id once logged in
	 *
	 * @var string $sid
	 */
	private string $sid = '';

	/**
	 * Login as the configured tool account
	 *
	 * Reads base_url, account and password from the application config
	 * under 'nas'. Throws when the configuration is missing, so the UI
	 * can show "not configured" before attempting anything.
	 *
	 * @access public
	 */
	public function __construct() {
		$config = Application::get()->config;
		$nas = $config->nas ?? [];

		$this->base_url = rtrim((string) ($nas['base_url'] ?? ''), '/');
		$this->account = (string) ($nas['account'] ?? '');
		$this->password = (string) ($nas['password'] ?? '');

		if ($this->base_url === '' || $this->account === '' || $this->password === '') {
			throw new \Exception('NAS configuration missing (config environment: nas base_url / account / password)');
		}
	}

	/**
	 * Is the NAS configuration complete
	 *
	 * Cheap check for the settings page: true when base_url, account and
	 * password are configured, without actually contacting the NAS.
	 *
	 * @access public
	 * @return bool $configured
	 */
	public static function is_configured(): bool {
		$config = Application::get()->config;
		$nas = $config->nas ?? [];

		return isset($nas['base_url'], $nas['account'], $nas['password']) === true
			&& $nas['base_url'] !== ''
			&& $nas['account'] !== ''
			&& $nas['password'] !== '';
	}

	/**
	 * Authenticate and open a session
	 *
	 * @access public
	 * @return string $sid
	 */
	public function login(): string {
		if ($this->sid !== '') {
			return $this->sid;
		}

		$response = $this->request('api=SYNO.API.Auth&version=6&method=login&format=sid', [
			'account' => $this->account,
			'passwd' => $this->password,
			'session' => 'FileStation',
		]);

		if (isset($response['sid']) === false || $response['sid'] === '') {
			throw new \Exception('FileStation login did not return a session id');
		}

		$this->sid = (string) $response['sid'];

		return $this->sid;
	}

	/**
	 * Close the session
	 *
	 * @access public
	 */
	public function logout(): void {
		if ($this->sid === '') {
			return;
		}

		$this->request('api=SYNO.API.Auth&version=6&method=logout&session=FileStation');
		$this->sid = '';
	}

	/**
	 * List one folder of the topos share
	 *
	 * @access public
	 * @param string $path Absolute NAS path, e.g. /volume1/homes/…/Topo's
	 * @return array Entries: name, path, is_dir, size, modified (Y-m-d H:i:s)
	 * @throws \Exception On API failure (credentials, permissions, path)
	 */
	public function list(string $path): array {
		$this->login();

		$body = [
			'api' => 'SYNO.FileStation.List',
			'version' => '2',
			'method' => 'list',
			'folder_path' => $path,
			'additional' => '["size", "mtime"]',
		];

		$response = $this->request('api=SYNO.FileStation.List&version=2&method=list', $body);
		$entries = [];

		foreach ($response['files'] ?? [] as $file) {
			$type = (string) ($file['additional']['type'] ?? ($file['type'] ?? ''));
			$is_dir = $type === 'dir';

			$modified = null;
			if (isset($file['additional']['mtime']) === true) {
				$modified = date('Y-m-d H:i:s', (int) $file['additional']['mtime']);
			}

			$entries[] = [
				'name' => basename((string) $file['path']),
				'path' => (string) $file['path'],
				'is_dir' => $is_dir,
				'size' => (int) ($file['additional']['size'] ?? 0),
				'modified' => $modified,
			];
		}

		return $entries;
	}

	/**
	 * Download a file into memory
	 *
	 * Topos files stay in the megabyte range; anything beyond that has
	 * no business flowing through a web action.
	 *
	 * @access public
	 * @param string $path Absolute NAS path of the file
	 * @return string $content
	 */
	public function download(string $path): string {
		$this->login();

		$query = http_build_query([
			'api' => 'SYNO.FileStation.Download',
			'version' => '2',
			'method' => 'download',
			'_sid' => $this->sid,
			'mode' => 'open',
			'path' => $path,
		]);

		$handle = curl_init($this->base_url . '/webapi/entry.cgi?' . $query);
		if ($handle === false) {
			throw new \Exception('FileStation download could not be opened');
		}

		$this->apply_tls($handle);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

		$content = curl_exec($handle);
		$error = curl_error($handle);
		$status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		curl_close($handle);

		if ($content === false || $status !== 200) {
			throw new \Exception('FileStation download failed (' . $status . ') ' . $error);
		}

		return (string) $content;
	}

	/**
	 * Perform one entry.cgi API call
	 *
	 * @access private
	 * @param string $query The api/version/method part of the url
	 * @param array $parameters Posted parameters (dsm's public route)
	 * @return array $response
	 * @throws \Exception On transport failure, bad credentials or API error
	 */
	private function request(string $query, array $parameters = []): array {
		$url = $this->base_url . '/webapi/entry.cgi?' . $query;
		if ($this->sid !== '') {
			$url .= '&_sid=' . urlencode($this->sid);
		}

		$handle = curl_init($url);
		if ($handle === false) {
			throw new \Exception('FileStation request could not be opened');
		}

		$this->apply_tls($handle);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_POST, true);
		curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($parameters));
		curl_setopt($handle, CURLOPT_TIMEOUT, 30);

		$content = curl_exec($handle);
		$error = curl_error($handle);
		$status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		curl_close($handle);

		if ($content === false || $status !== 200) {
			throw new \Exception('FileStation request failed (' . $status . ') ' . $error);
		}

		$response = json_decode((string) $content, true);
		if (is_array($response) === false || isset($response['success']) === false) {
			throw new \Exception('FileStation answered something that is not an API reply');
		}

		if ($response['success'] !== true) {
			$code = (string) ($response['error']['code'] ?? '?');
			throw new \Exception('FileStation API error ' . $code . ' on ' . strtok($query, '&'));
		}

		return (array) $response['data'];
	}

	/**
	 * Apply the TLS options shared by every call
	 *
	 * @access private
	 * @param \CurlHandle $handle
	 */
	private function apply_tls($handle): void {
		curl_setopt($handle, CURLOPT_USERAGENT, 'buysse.io-tools');
		curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		curl_setopt($handle, CURLOPT_TIMEOUT, 60);
	}
}
