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
	private $formatter = null;

	/**
	 * $channel,日志的通过主题，关于那方面的日志
	 * @var null
	 */
	private $channel = null;

	/**
	 * $logFilePath
	 * @var null
	 */
	private $logFilePath = null;

	/**
	 * $output,默认定义输出日志的文本格式
	 * @var string
	 */
	const output = "[%datetime%] %channel% > %level_name% : %message% \n";
	
	/**
	 * __construct
	 */
	public function __construct($channel=null,$logFilePath=null,$output=null,$dateformat=null) {
		$this->channel = $channel;
		$this->logFilePath = $logFilePath;
		$outputfomat = $output ? $output : self::output;
		//$formatter对象
		$this->formatter = new LineFormatter($outputfomat,$dateformat);
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
	 * @return 
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
	 * @return 
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
	 * @return 
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