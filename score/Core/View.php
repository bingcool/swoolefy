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
	 * $gzip_level
	 * @var integer
	 */
	public $gzip_level = 1;
	
	/**
	 * __construct
	 */
	public function __construct($contentType='text/html') {
		$smarty = new Smarty;
		$smarty->setCompileDir(SMARTY_COMPILE_DIR);
		$smarty->setCacheDir(SMARTY_CACHE_DIR);

		$this->content_type = $contentType;
		$this->view = $smarty;
		isset(Application::$app->config['gzip_level']) && $this->gzip_level = (int)Application::$app->config['gzip_level'];
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
		@Application::$app->response->header('Content-Type',$this->content_type.'; charset=utf-8');
		// 线上环境压缩
		Application::$app->response->gzip($this->gzip_level);
		// 分段返回数据,2M左右一段
		$response = @Application::$app->response;
		$p = 0;
		$size = 2000000;
		while($data = substr($tpl, $p++ * $size, $size)) {
             $response->write($data);
        }
		@Application::$app->response->end();
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
		$response->gzip($this->gzip_level);
		@$response->write($string);
		@$response->end();
	}

}