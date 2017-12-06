<?php
namespace Swoolefy\Tool;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class Log {
	/**
	 * $formatter,格式化对象
	 * @var null
	 */
	public $formatter = null;

	/**
	 * $channel,日志的通过主题，关于那方面的日志
	 * @var null
	 */
	public $channel = null;

	/**
	 * $logFilePath
	 * @var null
	 */
	public $logFilePath = null;

	/**
	 * $output,默认定义输出日志的文本格式
	 * @var string
	 */
	public $output = "[%datetime%] %channel% > %level_name% : %message% \n";
	
	/**
	 * __construct
	 */
	public function __construct($channel=null,$logFilePath=null,$output=null,$dateformat=null) {
		$this->channel = $channel;
		$this->logFilePath = $logFilePath;
		$output && $this->output = $output;
		//$formatter对象
		$this->formatter = new LineFormatter($this->output,$dateformat);
	}

	/**
	 * setChannel
	 * @param    $channel 
	 * @return   this 
	 */
	public function setChannel($channel) {
		$this->channel = $channel;
		return $this;
	}

	/**
	 * setLogFilePath
	 * @param   $logFilePath
	 * @return  this
	 */
	public function setLogFilePath($logFilePath) {
		$this->logFilePath = $logFilePath;
		return $this;
	}

	/**
	 * setOutputFormat
	 * @param    $output
	 * @return   this
	 */
	public function setOutputFormat($output) {
		$this->output = $output;
		$this->formatter = new LineFormatter($this->output,$dateformat=null);
		return $this;
	}

	/**
	 * info
	 * @param  $loginfo 
	 * @return 
	 */
	public function addInfo($logInfo) {
		$log = new Logger($this->channel);
		$stream = new StreamHandler($this->logFilePath, Logger::INFO);
		$stream->setFormatter($this->formatter);
		$log->pushHandler($stream);
		// add records to the log
		$log->info($logInfo);
	}

	/**
	 * Notice
	 * @param  $loginfo 
	 * @return void
	 */
	public function addNotice($logInfo) {
		$log = new Logger($this->channel);
		$stream = new StreamHandler($this->logFilePath, Logger::NOTICE);
		$stream->setFormatter($this->formatter);
		$log->pushHandler($stream);
		// add records to the log
		$log->notice($logInfo);
	}

	/**
	 * Warning
	 * @param  $loginfo 
	 * @return void
	 */
	public function addWarning($logInfo) {
		$log = new Logger($this->channel);
		$stream = new StreamHandler($this->logFilePath, Logger::WARNING);
		$stream->setFormatter($this->formatter);
		$log->pushHandler($stream);
		// add records to the log
		$log->warning($logInfo);
	}

	/**
	 * Error
	 * @param  $loginfo 
	 * @return void
	 */
	public function addError($logInfo) {
		$log = new Logger($this->channel);
		$stream = new StreamHandler($this->logFilePath, Logger::ERROR);
		$stream->setFormatter($this->formatter);
		$log->pushHandler($stream);
		// add records to the log
		$log->error($logInfo);
	}

}