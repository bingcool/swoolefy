<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Util;

use Swoolefy\Core\App;
use Swoolefy\Core\Log\Formatter\JsonFormatter;
use Swoolefy\Core\Log\Formatter\NormalizerFormatter;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Log\Logger;
use Swoolefy\Core\Log\StreamHandler;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Core\Swoole;

/**
 * Class Log
 * @package Swoolefy\Util
 * @method \Swoolefy\Util\Log info
 * @method \Swoolefy\Util\Log notice
 * @method \Swoolefy\Util\Log warning
 * @method \Swoolefy\Util\Log error
 */
class Log
{

    /**
     * @var string
     */
    public $type;

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
     * $output,默认定义输出日志的文本格式LineFormatter模式有效
     * @var string
     */
    public $output = "[%datetime%] %channel%:%level_name%:%message%:%context%\n";

    /**
     * $formatter 格式化对象
     * @var string
     */
    protected $formatter = null;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var
     */
    protected $handler;

    /**
     * @var string
     */
    protected $prefix = 'add';

    /**
     * @var string
     */
    protected $initDate;

    /**
     * @var string
     */
    protected $dateLogFilePath;

    /**
     * @param string $type
     * @param string|null $channel
     * @param string|null $logFilePath
     * @param string|null $output
     * @param string|null $dateformat
     * @throws \Exception
     */
    public function __construct(
        string $type,
        string $channel = null,
        string $logFilePath = null,
        string $output = null,
        string $dateformat = null
    ) {
        $this->type = $type;
        $this->channel = $channel;
        $this->logFilePath = $logFilePath;
        $output && $this->output = $output;
        $channel && $this->logger = new Logger($this->channel);
        // $formatter object
        // $this->formatter = new LineFormatter($this->output, $dateformat);
        $this->formatter = new JsonFormatter();
        if ($logFilePath) {
            $this->setLogFilePath($logFilePath);
        }
    }

    /**
     * @param string $type
     * @return $this
     */
    public function setType(string $type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * setChannel
     * @param string $channel
     * @return $this
     */
    public function setChannel(string $channel)
    {
        $this->channel = $channel;
        if (!$this->logger) {
            $this->logger = new Logger($this->channel);
        }
        return $this;
    }

    /**
     * setLogFilePath
     * @param string $logFilePath
     * @return $this
     */
    public function setLogFilePath(string $logFilePath)
    {
        $this->initDate = $this->getDate();
        $dateLogFilePath = $this->getDateLogFile($this->initDate, $logFilePath);
        $this->logFilePath = $logFilePath;
        $this->dateLogFilePath = $dateLogFilePath;
        $this->handler = new StreamHandler($this->dateLogFilePath);
        $this->handler->setFormatter($this->formatter);
        return $this;
    }

    /**
     * @param string $date
     * @param string $logFilePath
     * @return string
     */
    protected function getDateLogFile(string $date, string $logFilePath)
    {
        $fileInfo = pathinfo($logFilePath);
        return $fileInfo['dirname'].DIRECTORY_SEPARATOR.$fileInfo['filename'].'_'.$date.'.'.$fileInfo['extension'];
    }

    /**
     * @return string
     */
    protected function getDate()
    {
        return date('Ymd', time());
    }

    /**
     * setOutputFormat
     * @param string $output
     * @return $this
     */
    public function setOutputFormat(string $output)
    {
        $this->output = $output;
        return $this;
    }

    /**
     * @param NormalizerFormatter $formatter
     * @return void
     */
    public function setFormatter(NormalizerFormatter $formatter)
    {
        $this->formatter = $formatter;
    }

    /**
     * @return string
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     * @return null|string
     */
    public function getLogFilePath()
    {
        if($this->getDate() != $this->initDate) {
            $this->setLogFilePath($this->logFilePath);
        }
        return $this->dateLogFilePath;
    }

    /**
     * @return NormalizerFormatter
     */
    public function getOutputFormat()
    {
        return $this->formatter;
    }

    /**
     * addInfo
     * @param $logInfo
     * @param bool $is_delay_batch
     * @param array $context
     */
    public function addInfo($logInfo, bool $is_delay_batch = false, array $context = [])
    {
        $this->handleLog(__FUNCTION__, $logInfo, $is_delay_batch, $context, Logger::INFO);
    }

    /**
     * addNotice
     * @param $logInfo
     * @param bool $is_delay_batch
     * @param array $context
     * @param \Throwable
     */
    public function addNotice($logInfo, bool $is_delay_batch = false, array $context = [])
    {
        $this->handleLog(__FUNCTION__, $logInfo, $is_delay_batch, $context, Logger::NOTICE);
    }

    /**
     * addWarning
     * @param $logInfo
     * @param bool $is_delay_batch
     * @param array $context
     */
    public function addWarning($logInfo, bool $is_delay_batch = false, array $context = [])
    {
        $this->handleLog(__FUNCTION__, $logInfo, $is_delay_batch, $context, Logger::WARNING);
    }

    /**
     * addError
     * @param $logInfo
     * @param bool $is_delay_batch
     * @param array $context
     */
    public function addError($logInfo, bool $is_delay_batch = false, array $context = [])
    {
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
    protected function handleLog($function, $logInfo, $is_delay_batch, $context, $type)
    {
        $app = Application::getApp();
        if (is_object($app) && $is_delay_batch && Swfy::isWorkerProcess()) {
            $app->setLog($this->type, $function, [$logInfo, false, $context]);
            return true;
        }
        $this->insertLog($logInfo, $context, $type);
    }

    /**
     * @param mixed $logInfo
     * @param array $context
     * @param int $type
     */
    public function insertLog($logInfo, array $context = [], $type = Logger::INFO)
    {
        if (is_array($logInfo)) {
            $logInfo = json_encode($logInfo, JSON_UNESCAPED_UNICODE);
        }

        $App = Application::getApp();
        $callable = function () use ($type, $logInfo, $context, $App) {
            try {
                $this->logger->setHandlers([]);
                $this->logger->pushHandler($this->handler);

                $this->logger->pushProcessor(function ($records) use($App) {
                    return $this->pushProcessor($records, $App);
                });

                // add records to the log
                $this->logger->addRecord($type, $logInfo, $context);
            } catch (\Exception $e) {

            }
        };

        if (\Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::create(function () use ($callable) {
                call_user_func($callable);
            });
        } else {
            call_user_func($callable);
        }
    }

    /**
     * 可继承重写-定义公共的字段信息
     *
     * @param array $records
     * @param $App
     * @return array
     */
    protected function pushProcessor($records, $App = null): array
    {
        $records['timestamp'] = microtime(true);
        $records['hostname']  = gethostname();
        $records['process'] = 'task_worker|use_self_worker';
        $records['url'] = '';
        $records['require_params'] = [];
        if (Swfy::isWorkerProcess()) {
            $records['process'] = 'worker_process';
            if ($App instanceof App) {
                $records['url'] = $App->getRequestUri();
                $records['request_params'] = $App->getRequestParams();
            }else if ($App instanceof Swoole) {
                $records['url'] = $App->getServiceHandle();
                $records['request_params'] = $App->getMixedParams();
            }
        }else if (Swfy::isTaskProcess()) {
            $records['process'] = 'task_worker';
        }else if (Swfy::isSelfProcess()) {
            $records['process'] = 'use_self_worker';
        }

        if (defined('IS_WORKER_SERVICE') && IS_WORKER_SERVICE) {
            $records['process'] = 'worker_service';
        }

        if (defined('IS_CLI_SCRIPT') && IS_CLI_SCRIPT) {
            $records['process'] = 'cli_script_service';
        }

        return $records;
    }

    /**
     * @param $method
     * @param $methodName
     */
    public function __call(string $method, array $args)
    {
        $methodName = $this->prefix . ucfirst($method);
        $this->$methodName(...$args);
    }

}