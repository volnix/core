<?php

namespace Toadsuck\Core;

use Toadsuck\Core\Template;
use Toadsuck\Core\Config;
use Toadsuck\Core\Input;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class Controller
{
	public $plates;
	public $template;
	public $config;
	public $request;
	public $session;
	public $ds = DIRECTORY_SEPARATOR;
	public $app;
	public $input;

	public function __construct($opts = [])
	{
		// Set the instance of our application
		$this->app = array_key_exists('app_instance', $GLOBALS) ? $GLOBALS['app_instance'] : null;

		// Where is our app's source code?
		$app_dir = array_key_exists('app_dir', $opts) ? $opts['app_dir'] : null;

		// Set up configs.
		$this->initializeConfig($app_dir);

		// Set up the template engine.
		$this->initializeTemplate();

		// Set up our HTTP Request object.
		$this->initializeRequest();

		// Initialize the Session.
		$this->initializeSession();
	}

	/**
	 * Set up the configuration manager.
	 * @param string $app_dir Filesystem path to the config directory
	 */
	public function initializeConfig($app_dir = null)
	{
		$this->config = new Config($app_dir);
	}

	/**
	 * Set up the template system
	 * @param string $directory Filesystem path to the views directory.
	 */
	public function initializeTemplate($directory = null)
	{
		if (empty($directory)) {
			$directory = $this->config->resolvePath('views');
		}

		$this->template = new Template($directory, $this->config);

		// Add our url builder to the template.
		$extension = new \werx\Url\Extensions\Plates();
		$this->template->loadExtension($extension);
	}

	/**
	 * Get info about the HTTP Request
	 */
	public function initializeRequest($request = null)
	{
		if (empty($request)) {
			$this->request = Request::createFromGlobals();
		} else {
			$this->request = $request;
		}

		// Shortcuts to the request object for cleaner syntax.
		$this->input = new Input($this->request);
	}

	/**
	 * Initialize the session.
	 *
	 * This is something you might want to override in your controller so you can
	 * redirect to a page with a message about being logged out after detecting the session has expired.
	 *
	 */
	protected function initializeSession($session_expiration = null)
	{
		// Setup the session
		$this->session = new Session();

		$this->config->load('config');

		// We need a unique session name for this app. Let's use last 10 characters the file path's sha1 hash.
		try {
			$this->session->setName('TSAPP' . substr(sha1(__FILE__), -10));
			$this->session->start();

			// Default session expiration 1 hour.
			// Can be overridden in method param or by setting session_expiration in config.php
			$session_expiration = !empty($session_expiration)
				? $session_expiration
				: $this->config->get('session_expiration', 3600);

			// Is this session too old?
			if (time() - $this->session->getMetadataBag()->getLastUsed() > $session_expiration) {
				$this->session->invalidate();
			}
		} catch (\LogicException $e) {
			// Session already active, can't change it now!
		}
	}

	/**
	 * Internal or External Redirect to the specified url
	 */
	public function redirect($url, $params = [], $is_query_string = false)
	{
		if (!preg_match('/^http/', $url)) {
			$url_builder = new \werx\Url\Builder;

			if ($is_query_string && is_array($params)) {
				$url = $url_builder->query($url, $params);
			} else {
				$url = $url_builder->action($url, $params);
			}
		} else {
			// External url. Just do a basic expansion.
			$url_builder = new \Rize\UriTemplate;
			$url = $url_builder->expand($url, $params);
		}

		/**
		 * You MUST call session_write_close() before performing a redirect to ensure the session is written,
		 * otherwise it might not happen quickly enough to save your session changes.
		 */
		session_write_close();

		$response = new RedirectResponse($url);
		$response->send();
	}

	public function json($content = [])
	{
		$response = new JsonResponse();
		$response->setData($content);
		$response->send();
	}

	public function jsonp($content = [], $jsonCallback = 'callback')
	{
		$response = new JsonResponse();
		$response->setData($content);
		$response->setCallback($jsonCallback);
		$response->send();
	}

	public function __call($method = null, $args = null)
	{
		// Send a 404 for any methods that don't exist.
		$response = new Response('Not Found', 404, ['Content-Type' => 'text/plain']);
		$response->send();
	}

	public function getRequestedController($default = null)
	{
		if (property_exists($this, 'app') && is_object($this->app) && property_exists($this->app, 'controller')) {
			return $this->app->controller;
		} elseif (!empty($default)) {
			return $default;
		} else {
			$reflect = new \ReflectionClass($this);
			return strtolower($reflect->getShortName());
		}
	}

	public function getRequestedAction($default = 'index')
	{
		if (property_exists($this, 'app') && is_object($this->app) && property_exists($this->app, 'action')) {
			return $this->app->action;
		} else {
			return $default;
		}
	}
}
