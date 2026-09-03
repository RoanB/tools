<?php
/**
 * Security Event
 *
 * Configures session cookie parameters.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Event;

use Skeleton\Application\Web\Event\Security as SecurityBase;

class Security extends SecurityBase {

	/**
	 * Set session cookie parameters
	 *
	 * @access public
	 */
	public function session_cookie(): void {
		session_set_cookie_params([
			'lifetime' => 0,
			'path' => '/',
			'secure' => true,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
	}
}
