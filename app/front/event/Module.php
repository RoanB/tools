<?php
/**
 * Module Event
 *
 * Handles access denied (redirect to login) and not found (404).
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Event;

use Skeleton\Application\Web\Event\Module as ModuleEventBase;
use Skeleton\Application\Web\Template;
use Skeleton\Core\Http\Session;
use Skeleton\Core\Http\Status;

class Module extends ModuleEventBase {

	/**
	 * Access denied handler
	 *
	 * Redirects unauthenticated users to the login page.
	 *
	 * @access public
	 * @param \Skeleton\Application\Web\Module $module
	 */
	public function access_denied(\Skeleton\Application\Web\Module $module): void {
		Session::redirect('/login');
	}

	/**
	 * Not found handler
	 *
	 * Shows a 404 page.
	 *
	 * @access public
	 */
	public function not_found(): void {
		Status::code_404('module');
		$template = Template::get();
		$template->display('error404.twig');
	}
}
