<?php
namespace Swoolefy\Tool;

use Swift_SmtpTransport;
use Swift_Mailer;
use Swift_Message;
use Swift_Attachment;
use Swift_IoException;

class Swiftmail {

	/**
	 * $smtpTransport
	 * @var array
	 * @eg
	 * [
	 * 		"server_host"=>"smtp.163.com",
	 * 		"port"      =>25,
	 * 		"security"  =>null || ssl
	 * 		"user_name" =>"13560491950@163.com",
	 * 		"pass_word" =>"*******"
	 * 	]
	 */
	public $smtpTransport = [];

	/**
	 * $message
	 * @var array
	 * @eg
	 * [
	 * 		//邮箱主题
	 * 		"subject"=>"hhhhh",
	 * 		//发送者邮箱与定义的名称，邮箱与上面定义的user_name这里必须一致
	 * 		"from"   =>["13560491950@163.com"=>"bingcool"],
	 * 		//定义多个收件人和对应的名称
	 * 		"to"     =>["qsddd223@qq.com","fghhjj@gmail.com"=>"bingcool",.....],
	 * 		//定义邮件的内容，格式可以包含html
	 * 		"body"   =>"<p>this is a mail</p>",
	 * 		// body文档类型
	 *	    "mime"   =>"text/html",
	 * 		//定义要上传的附件，可以多个，附件的大小，由代理的邮件服务器定义提供,key值代表是文件路径，name值代表是发送后的文件显示的别名，如果没设置name值，则以原文件名作为别名
	 * 		"attach" =>["/home/test.docx"=>"my.docx","/home/my.pdf","/home/my.png",.....],
	 *	
	 * ]
	 */
	public $message = [];
	/**
	 * __construct	 
	 */
	public function __construct($smtpTransport=null,$message=null) {
		is_array($smtpTransport) && $this->smtpTransport = $smtpTransport;
		is_array($message) && $this->message = $message; 
	}

	/**
	 * setSmtpTransport 动态设置smtp的信息
	 * @param   $smtpTransport
	 * @return  void
	 */
	public function setSmtpTransport($smtpTransport) {
		is_array($smtpTransport) && $this->smtpTransport = $smtpTransport;
	}

	/**
	 * setMessage 动态设置message发送信息
	 * @param    $message
	 * @return   void
	 */
	public function setMessage($message) {
		is_array($message) && $this->message = $message;
	}

	/**
	 * initSmtpTransport
	 * @return onject
	 */
	private function initSmtpTransport() {
		if(is_array($this->smtpTransport)) {
			$transport = (new Swift_SmtpTransport($this->smtpTransport["server_host"], $this->smtpTransport["port"], $this->smtpTransport["security"] ? $this->smtpTransport["security"] : null))
			->setUsername($this->smtpTransport["user_name"])
			->setPassword($this->smtpTransport["pass_word"]);
			return $transport;
		}
	}

	/**
	 * initMessage
	 * @return object
	 */
	private function initMessage() {
		if(is_array($this->message)) {
			$message = (new Swift_Message())
			 // 设置主题
			  ->setSubject($this->message["subject"])

			  // 设置发送人
			  ->setFrom($this->message["from"])

			  // 设置将要发送的邮件者
			  ->setTo($this->message["to"])

			  // 设置body
			  ->setBody($this->message["body"],$this->message["mime"] ? $this->message["mime"] : 'text/html');

		 	//循环附件 
		 	if($this->message["attach"]) {
			  	foreach($this->message["attach"] as $key => $attach) {
					if($key) {
						$filePath = $key;
						$aliaName = $attach;
					}else {
						$filePath = $attach;
						$aliaName = @end(explode('/',$attach));
					}
					// 设置附件
					$message->attach(Swift_Attachment::fromPath($filePath)->setFilename($aliaName));
				}
			}

			return $message;
		}
	}

	/**
	 * setSubject
	 * @return void
	 */
	public function setSubject($subject) {
		$this->message["subject"] = $subject;
	}

	/**
	 * setFrom
	 * @return void
	 */
	public function setFrom(array $from) {
		$this->message["from"] = $from;
	}

	/**
	 * setTo
	 * @return [type] [description]
	 */
	public function setTo(array $To) {
		$this->message["to"] = $To;
	}

	/**
	 * setBody
	 * @return void
	 */
	public function setBody($body) {
		$this->message["body"] = $body;
	}

	/**
	 * setAttach
	 * @return void
	 */
	public function setAttach(array $attach) {
		$this->message["attach"] = $attach;
	}

	/**
	 * sendEmail
	 * @return void
	 */
	public function sendEmail() {
		$transport = $this->initSmtpTransport();
		$message = $this->initMessage();
		// 创建mailer发送对象
		$mailer = new Swift_Mailer($transport);
		// 尝试发送，捕捉异常
		try {

			$mailer->send($message);
			
		}catch (Swift_IoException $e) {
			throw new \Exception($e->getMessage(), 1);
		}
	}
}