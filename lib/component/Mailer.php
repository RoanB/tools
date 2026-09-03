<?php
/**
 * Mailer
 *
 * Creates Skeleton email objects for outgoing mail.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

class Mailer {

	/**
	 * Create an email object of the given type
	 *
	 * @access public
	 * @param string $type
	 * @return \Skeleton\Email\Email $email
	 */
	public static function create(string $type): \Skeleton\Email\Email {
		$email = new \Skeleton\Email\Email($type);

		return $email;
	}
}
