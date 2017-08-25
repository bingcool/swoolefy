<?php

namespace Swoolefy\Core;

use Swoolefy\Core\Application;

class HttpResponseCode {
	/**
	 * http code status 200
	 */
	const status_200 = 200;
	
	/**
	 * http code status 302
	 */
	const status_302 = 302;

	/**
	 * http code status 404
	 */
	const status_404 = 404;

	/**
	 * http code status 500
	 */
	const status_500 = 500;
	
	/**
	 * status404
	 */
	public static function status404($content) {
		$response = Application::$app->response;
	}
}