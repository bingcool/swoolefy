<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Object;
use Swoolefy\Core\Pack;
use Swoolefy\Core\Application;

class SController extends Object {

	/**
	 * $previousUrl,记录url
	 * @var array
	 */
	public static $previousUrl = [];

	/**
	 * $selfModel 控制器对应的自身model
	 * @var array
	 */
	public static $selfModel = [];

	/**
	 * $fd 
	 * @var null
	 */
	public $fd = null;

	/**
	 * __construct
	 */
	public function __construct() {

		$this->fd = Application::$app->fd;
	}

	/**
	 * return 返回数据
	 * @param  mixed  $data
	 * @param  string $encode
	 * @return void
	 */
	public function return() {
		$args = func_get_args();
		// 客户端是eof方式分包
		if($this->isClientPackEof()) {
			list($data) = $args;
			$eof = Swfy::$config['packet']['client']['pack_eof'];
			$serialize_type = Swfy::$config['packet']['client']['serialize_type'];
			if($eof) {
				$return_data = Pack::enpackeof($data, $serialize_type, $eof);
			}else {
				$return_data = Pack::enpackeof($data, $serialize_type);
			}
			Swfy::$server->send($this->fd, $return_data);

		}else {
			// 客户端是length方式分包
			list($data, $header) = $args; 
			$header_struct = Swfy::$config['packet']['client']['pack_header_strct'];
			$pack_length_key = Swfy::$config['packet']['client']['pack_length_key'];
			$serialize_type = Swfy::$config['packet']['client']['serialize_type'];

			$header[$pack_length_key] = '';

			$return_data = Pack::enpack($data, $header, $header_struct, $pack_length_key, $serialize_type);
			Swfy::$server->send($this->fd, $return_data);
		}	
	}

	/**
	 * isClientPackEof 
	 * @return boolean [description]
	 */
	public function isClientPackEof() {
		if(isset(Swfy::$config['packet']['client']['pack_check_type'])) {
			if(Swfy::$config['packet']['client']['pack_check_type'] == 'eof') {
				//$client_check是eof方式
				return true;
			}
			return false;
		}else {
			throw new \Exception("you must set ['packet']['client']  in the config file", 1);	
		}
		
	}

	/**
	 * beforeAction 在处理实际action之前执行
	 * @return   mixed
	 */
	public function _beforeAction() {
		return true;
	}

	/**
	 * afterAction 在返回数据之前执行
	 * @return   mixed
	 */
	public function _afterAction() {
		return true;
	}

	/**
	 * __destruct 返回数据之前执行,重新初始化一些静态变量
	 */
	public function __destruct() {
		if(method_exists($this,'_afterAction')) {
			static::_afterAction();
		}
		// 初始化这个变量
		static::$previousUrl = [];
		// 初始化清除所有得单例model实例
		static::$selfModel = [];
	}

	use \Swoolefy\Core\ServiceTrait;
}