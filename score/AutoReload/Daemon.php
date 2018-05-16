<?php
/**
-+----------------------------------------------------------------------
-| swoolefy framework bases on swoole extension development, we can use it easily!
-+----------------------------------------------------------------------
-| Licensed ( https://opensource.org/licenses/MIT )
-+----------------------------------------------------------------------
-| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
-+----------------------------------------------------------------------
-*/

namespace Swoolefy\AutoReload;

use Swoolefy\AutoReload\Reload;

class Daemon {
	/**
	 * $config 配置
	 * @var array
	 */
	public $config = [
		'afterNSeconds' => 3,
		'isOnline' => false,
		'monitorPort' => 9502,
		'monitorPath' => '/home/www/swoolefy',
		'logFilePath' => START_DIR_ROOT.'/protocol/monitor/inotify.log',
		'reloadFileTypes' => ['.php','.html','.js'],
		'smtpTransport' => [
			"server_host"=>"smtp.163.com",
			"port"      =>25,
			"security"  =>null,
			"user_name" =>"13560491950@163.com",
			"pass_word" =>"XXXXXX"
		],

		'message' => [
			//邮箱主题
			"subject"=>"test",
			//发送者邮箱与定义的名称，邮箱与上面定义的user_name这里必须一致
			"from"   =>["13560491950@163.com"=>"bingcool"],
			//定义多个收件人和对应的名称
			"to"     =>['2437667702@qq.com'=>"bingcool", "bingcoolhuang@gmail.com"=>"dabing"],
			//定义邮件的内容，格式可以包含html
			"body"   =>"<p>this is a mail</p>",
			// body文档类型
			"mime"   =>"text/html",
			//定义要上传的附件，可以多个，附件的大小，由代理的邮件服务器定义提供,key值代表是文件路径，name值代表是发送后的文件显示的别名，如果没设置name值，则以原文件名作为别名
			// 	"attach" =>["/home/wwwroot/default/swoolefy/score/Test/test.docx"=>"my.docx","/home/wwwroot/default/swoolefy/score/Test/test.log","/home/wwwroot/default/swoolefy/score/Test/test.log"=>"my.log"],
			// ];
		]


	];

	/**
	 * __construct 初始化函数
	 * @param    {String}
	 */
	public function __construct(array $config = []) {	
		$this->config = array_merge($this->config, $config);
	}

	/**
	 * autoReload文件变动的自动检测与swoole自动重启服务
	 * @param    null
	 * @return   void
	 */
	public function autoReload() {
		$autoReload = new Reload();
		isset($this->config['afterNSeconds']) && $autoReload->afterNSeconds = $this->config['afterNSeconds'];
		isset($this->config['isOnline']) && $autoReload->isOnline = $this->config['isOnline'];
		isset($this->config['monitorPort']) && $autoReload->monitorPort = $this->config['monitorPort'];
		isset($this->config['logFilePath']) && $autoReload->logFilePath = $this->config['logFilePath'];
		isset($this->config['reloadFileTypes']) && $autoReload->reloadFileTypes = $this->config['reloadFileTypes'];
		isset($this->config['smtpTransport']) && $autoReload->smtpTransport = $this->config['smtpTransport'];
		isset($this->config['message']) && $autoReload->message = $this->config['message'];
		// 初始化配置
		$autoReload->init();
		// 开始监听
		$autoReload->watch($this->config['monitorPath']);
	}

	// 启动服务的eventloop
	public function run() {
		$this->autoReload();
		swoole_event_wait();
	}
}