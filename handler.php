<?php
/**
 * Initialize the application
 *
 * @author Christophe Gosiau <christophe@tigron.be>
 * @author Gerry Demaret <gerry@tigron.be>
 * @author David Vandemaele <david@tigron.be>
 */
require_once 'lib/base/Bootstrap.php';
Bootstrap::boot();


\Skeleton\Core\Http\Handler::Run();
