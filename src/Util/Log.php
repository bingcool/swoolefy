<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
 */

namespace Swoolefy\Util;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Log\Logger;
use Swoolefy\Core\Log\StreamHandler;
use Swoolefy\Core\Log\Formatter\LineFormatter;

class Log {
	/**
	 * $channel,日志的通过主题，关于那方面的日志
	 * @var string
	 */
	public $channel = null;

	/**
	 * $logFilePath
	 * @var string
	 */
	public $logFilePath = null;

	/**
	 * $output,默认定义输出日志的文本格式
	 * @var string
	 */
    public $output = "[%datetime%] %channel%:%level_name%:%message%:%context%\n";

    /**
     * $formatter 格式化对象
     * @var string
     */
    protected $formatter = null;

    /**
     * @var string
     */
    protected $prefix = 'add';
	
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
	 * @return   $this
	 */
	public function setChannel($channel) {
		$this->channel = $channel;
		return $this;
	}

	/**
	 * setLogFilePath
	 * @param   string $logFilePath
	 * @return  $this
	 */
	public function setLogFilePath($logFilePath) {
		$this->logFilePath = $logFilePath;
		return $this;
	}

	/**
	 * setOutputFormat
	 * @param  string $output
	 * @return $this
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
     * @param bool $is_delay_batch
     * @param array $context
     */
	public function addInfo($logInfo, $is_delay_batch = false, array $context = []) {
        $this->handleLog(__FUNCTION__, $logInfo, $is_delay_batch, $context, Logger::INFO);
	}

    /**
     * addNotice
     * @param $logInfo
     * @param bool $is_delay_batch
     * @param array $context
     * @param \Throwable
     */
	public function addNotice($logInfo, $is_delay_batch = false, array $context = []) {
        $this->handleLog(__FUNCTION__, $logInfo, $is_delay_batch, $context, Logger::NOTICE);
	}

    /**
     * addWarning
     * @param $logInfo
     * @param bool $is_delay_batch
     * @param array $context
     */
	public function addWarning($logInfo, $is_delay_batch = false, array $context = []) {
        $this->handleLog(__FUNCTION__, $logInfo, $is_delay_batch, $context, Logger::WARNING);
	}

    /**
     * addError
     * @param $logInfo
     * @param bool $is_delay_batch
     * @param array $context
     */
	public function addError($logInfo, $is_delay_batch = false, array $context = []) {
        $this->handleLog(__FUNCTION__, $logInfo, $is_delay_batch, $context, Logger::ERROR);
	}

    /**
     * @param $function
     * @param $is_delay_batch
     * @param $logInfo
     * @param $context
     * @param $type
     * @return bool
     * @throws \Exception
     */
    protected function handleLog($function, $logInfo, $is_delay_batch, $context, $type) {
        $app = Application::getApp();
        if(is_object($app) && $is_delay_batch && Swfy::isWorkerProcess()) {
            $app->setLog($function, [$logInfo, false, $context]);
            return true;
        }
        $this->insertLog($logInfo, $context, $type);
    }

    /**
     * @param $logInfo
     * @param $context
     * @param int $type
     */
	public function insertLog($logInfo, array $context = [], $type = Logger::INFO) {
        if(is_array($logInfo)) {
            $logInfo = json_encode($logInfo, JSON_UNESCAPED_UNICODE);
        }

        $callable = function () use($type, $logInfo, $context) {
            try{
                $log = new Logger($this->channel);
                $stream = new StreamHandler($this->logFilePath, $type);
                $stream->setFormatter($this->formatter);
                $log->pushHandler($stream);
                // add records to the log
                $log->addRecord($type, $logInfo, $context);
            }catch (\Exception $e) {

            }
        };

        if(\Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::create(function() use($callable) {
                call_user_func($callable);
            });
        }else {
            call_user_func($callable);
        }

    }

    /**
     * @param $method
     * @param $methodName
     */
    public function __call($method, $args) {
	    $methodName = $this->prefix.ucfirst($method);
	    $this->$methodName(...$args);
    }

}