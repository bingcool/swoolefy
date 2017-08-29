<?php
namespace Swoolefy\Core;

trait AppTrait {
	/**
	 * $view
	 * @var null
	 */
	public $view = null;
	/**
	 * isGet
	 * @return boolean
	 */
	public function isGet() {
		return ($this->request->server['request_method'] == 'GET') ? true :false;
	}

	/**
	 * isPost
	 * @return boolean
	 */
	public function isPost() {
		return ($this->request->server['request_method'] == 'POST') ? true :false;
	}

	/**
	 * isPut
	 * @return boolean
	 */
	public function isPut() {
		return ($this->request->server['request_method'] == 'PUT') ? true :false;
	}

	/**
	 * isDelete
	 * @return boolean
	 */
	public function isDelete() {
		return ($this->request->server['request_method'] == 'DELETE') ? true :false;
	}

	/**
	 * isAjax
	 * @return boolean
	 */
	public function isAjax() {
		return (isset($this->request->header['x-requested-with']) && strtolower($this->request->header['x-requested-with']) == 'xmlhttprequest') ? true : false;
	}

	/**
	 * getMethod 
	 * @return   string
	 */
	public function getMethod() {
		return $this->request->server['request_method'];
	}

	/**
	 * getRequestUri
	 * @return string
	 */
	public function getRequestUri() {
		return $this->request->server['path_info'];
	}

	/**
	 * getRoute
	 * @return array
	 */
	public function getRoute() {
		$require_uri = $this->getRequestUri();
		$route_uri = substr($require_uri,1);
		$route_arr = explode('/',$route_uri);
		if(count($route_arr) == 1){
			$route_arr[1] = 'index';
		}
		return $route_arr;
	}

	/**
	 * getModel 
	 * @return string|null
	 */
	public function getModel() {
		$route_arr = $this->getRoute();
		if(count($route_arr) == 3) {
			return $route_arr[0];
		}else {
			return null;
		}
	}

	/**
	 * getController
	 * @return string
	 */
	public function getController() {
		$route_arr = $this->getRoute();
		if(count($route_arr) == 3) {
			return $route_arr[1];
		}else {
			return $route_arr[0];
		}
	}

	/**
	 * getAction
	 * @return string
	 */
	public function getAction() {
		$route_arr = $this->getRoute();
		return array_pop($route_arr);
	}

	/**
	 * getQuery
	 * @return string
	 */
	public function getQuery() {
		return $this->request->get;
	}

	/**
	 * assign
	 * @param   $name
	 * @param   $value
	 * @return       
	 */
	public function assign($name,$value) {
		$this->view = new \Swoolefy\Core\View;
		$this->view->assign($name,$value);
	}

	/**
	 * display
	 * @param    $template_file
	 * @return                 
	 */
	public function display($template_file=null) {
		$this->view->display($template_file);
	}

	/**
	 * @DateTime 2017-08-29
	 * @param    $template_file
	 * @return                 
	 */
	public function fetch($template_file=null) {
		$this->view->display($template_file);
	}

	/**
	 * returnJson
	 * @param    $data    
	 * @param    $formater
	 * @return            
	 */
	public function returnJson($data,$formater = 'json') {
		$view = new \Swoolefy\Core\View;
		$view->returnJson($data,$formater);
	}	
}