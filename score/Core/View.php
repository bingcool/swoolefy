<?php
namespace Swoolefy\Core;

use Smarty;
use Swoolefy\Core\Application;

class View {
	/**
	 * $view
	 * @var null
	 */
	public $view = null;

	/**
	 * $content_type
	 * @var null
	 */
	public $content_type = null;
	/**
	 * __construct
	 */
	public function __construct($contentType='text/html') {
		$smarty = new Smarty;
		$smarty->setCompileDir(SMARTY_COMPILE_DIR);
		$smarty->setCacheDir(SMARTY_CACHE_DIR);

		$this->content_type = $contentType;
		$this->view = $smarty;
	}

	/**
	 * assign
	 * @param    $name
	 * @param    $value
	 * @return   
	 */
	public function assign($name,$value) {
		$this->view->assign($name,$value);
	}

	/**
	 * display
	 * @param    $template_file
	 * @return                 
	 */
	public function display($template_file=null) {
		$controller = Application::$app->getController();
		
		$action = Application::$app->getAction();

		$TemplateDir = SMARTY_TEMPLATE_PATH.$controller.'/';
		if(is_dir($TemplateDir)) {
			$this->view->setTemplateDir($TemplateDir);
		}else {
			$this->view->setTemplateDir(SMARTY_TEMPLATE_PATH);
		}

		if(!$template_file) {
			$template_file = $action.'.html';
		}

		$filePath = $TemplateDir.$template_file;
		if(is_file($filePath)) {
			$tpl = $this->view->fetch($template_file);
		}else {
			$tpl = $template_file;
		}
		@Application::$app->response->header('Content-Type',$this->content_type.'; charset=UTF-8');
		// 线上环境压缩
		if(!SW_DEBUG) Application::$app->response->gzip(1);
		@Application::$app->response->end($tpl);
	}

	/**
	 * fetch
	 * @param    $template_file
	 * @return                 
	 */
	public function fetch($template_file=null) {
		$this->display($template_file);
	}

	/**
	 * json
	 * @param    $data
	 * @param    $formater
	 * @return        
	 */
	public function returnJson($data,$formater = 'json') {
			$response = Application::$app->response;
			switch(strtoupper($formater)) {
				case 'JSON':
					$response->header('Content-Type','application/json; charset=utf-8');
					$string = json_encode($data,0);
				break;
				case 'XML':
					$response->header('Content-Type','text/xml; charset=utf-8');
               		$string = xml_encode($data);
                break;
                case 'EVAL':
                	$response->header('Content-Type','text/xml; charset=utf-8');
                	$string = $data;
				default:$string = json_encode($data,0);break;
			}
			// 线上环境压缩
			if(!SW_DEBUG) $response->gzip(1);
			@$response->end($string);
	}

}