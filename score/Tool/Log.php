<?php
namespace Swoolefy\Tool;

use Swoolefy\Core\Application;
use Swoolefy\Core\Log\Logger;
use Swoolefy\Core\Log\StreamHandler;
use Swoolefy\Core\Log\Formatter\LineFormatter;

class Log {
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
     * $formatter 格式化对象
     * @var null
     */
    protected $formatter = null;
	
	/**
	 * __construct
	 */
	public function __construct(
        string $channel = null,
        string $logFilePath = null,
        string $output = null,
        string $dateformat = null)
    {
		$this->channel = $channel;
		$this->logFilePath = $logFilePath;
		$output && $this->output = $output;
		//$formatter对象
		$this->formatter = new LineFormatter($this->output, $dateformat);
	}

	/**
	 * setChannel
	 * @param    string $channel
	 * @return   mixed  $this
	 */
	public function setChannel($channel) {
		$this->channel = $channel;
		return $this;
	}

	/**
	 * setLogFilePath
	 * @param   string $logFilePath
	 * @return  mixed  $this
	 */
	public function setLogFilePath($logFilePath) {
		$this->logFilePath = $logFilePath;
		return $this;
	}

	/**
	 * setOutputFormat
	 * @param    string $output
	 * @return   mixed $this
	 */
	public function setOutputFormat($output) {
		$this->output = $output;
		$this->formatter = new LineFormatter($this->output, $dateformat = null);
		return $this;
	}

    /**
     * @return null|string
     */
	public function getChannel() {
	    return $this->channel;
    }

    /**
     * @return null|string
     */
    public function getLogFilePath() {
	    return $this->logFilePath;
    }

    /**
     * @return null|string
     */
    public function getOutputFormat() {
        return $this->formatter;
    }

    /**
     * addInfo
     * @param $logInfo
     * @param bool $is_deplay_batch
     * @param array $context
     */
	public function addInfo($logInfo, $is_deplay_batch = false, array $context = []) {
	    if(is_array($logInfo)) {
            $logInfo = json_encode($logInfo);
        }
	    $app = Application::getApp();
	    if(is_object($app) && $is_deplay_batch) {
            $app->setLog(__FUNCTION__, $logInfo);
            return;
        }
        go(function() use($logInfo, $context) {
            $log = new Logger($this->channel);
            $stream = new StreamHandler($this->logFilePath, Logger::INFO);
            $stream->setFormatter($this->formatter);
            $log->pushHandler($stream);
            // add records to the log
            $log->info($logInfo, $context);
        });
	}

    /**
     * addNotice
     * @param $logInfo
     * @param bool $is_deplay_batch
     * @param array $context
     */
	public function addNotice($logInfo, $is_deplay_batch = false, array $context = []) {
        if(is_array($logInfo)) {
            $logInfo = json_encode($logInfo);
        }
        $app = Application::getApp();
        if(is_object($app) && $is_deplay_batch) {
            $app->setLog(__FUNCTION__, $logInfo);
            return;
        }
        go(function() use($logInfo, $context) {
            $log = new Logger($this->channel);
            $stream = new StreamHandler($this->logFilePath, Logger::NOTICE);
            $stream->setFormatter($this->formatter);
            $log->pushHandler($stream);
            // add records to the log
            $log->notice($logInfo, $context);
        });
	}

    /**
     * addWarning
     * @param $logInfo
     * @param bool $is_deplay_batch
     * @param array $context
     */
	public function addWarning($logInfo, $is_deplay_batch = false, array $context = []) {
        if(is_array($logInfo)) {
            $logInfo = json_encode($logInfo);
        }
        $app = Application::getApp();
        if(is_object($app) && $is_deplay_batch) {
            $app->setLog(__FUNCTION__, $logInfo);
            return;
        }
        go(function() use($logInfo, $context) {
            $log = new Logger($this->channel);
            $stream = new StreamHandler($this->logFilePath, Logger::WARNING);
            $stream->setFormatter($this->formatter);
            $log->pushHandler($stream);
            // add records to the log
            $log->warning($logInfo, $context);
        });
	}

    /**
     * addError
     * @param $logInfo
     * @param bool $is_deplay_batch
     * @param array $context
     */
	public function addError($logInfo, $is_deplay_batch = false, array $context = []) {
        if(is_array($logInfo)) {
            $logInfo = json_encode($logInfo);
        }
        $app = Application::getApp();
        if(is_object($app) && $is_deplay_batch) {
            $app->setLog(__FUNCTION__, $logInfo);
            return;
        }
        go(function() use($logInfo, $context) {
            $log = new Logger($this->channel);
            $stream = new StreamHandler($this->logFilePath, Logger::ERROR);
            $stream->setFormatter($this->formatter);
            $log->pushHandler($stream);
            // add records to the log
            $log->error($logInfo, $context);
        });
	}

}