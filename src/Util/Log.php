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

use Common\Library\CurlProxy\OpentelemetryMiddleware;
use Swoolefy\Core\App;
use Swoolefy\Core\Log\Formatter\JsonFormatter;
use Swoolefy\Core\Log\Formatter\NormalizerFormatter;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Log\Logger;
use Swoolefy\Core\Log\StreamHandler;
use Swoolefy\Core\Swoole;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Core\Coroutine\Context as SwooleContext;

/**
 * Class Log
 * @package Swoolefy\Util
 * @method \Swoolefy\Util\Log info($logInfo, bool $isDelayBatch = false, array $context = [])
 * @method \Swoolefy\Util\Log notice($logInfo, bool $isDelayBatch = false, array $context = [])
 * @method \Swoolefy\Util\Log warning($logInfo, bool $isDelayBatch = false, array $context = [])
 * @method \Swoolefy\Util\Log error($logInfo, bool $isDelayBatch = false, array $context = [])
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
     * 日期/小时轮
     * @var string
     */
    protected $initDateRound;

    /**
     * @var string
     */
    protected $dateLogFilePath;


    /**
     * @var int
     */
    protected $rotateDay = 7;

    /**
     * @var bool
     */
    protected $doUnlink = false;

    /**
     * @var bool
     */
    protected $hourly = false;

    /**
     * Stream handler periodic reopen interval in seconds, 0 disables.
     * 默认 不启用周期强制 reopen（0）
     * @var int
     */
    protected $handlerReopenInterval = 0;

    /**
     * Stream handler inode check interval in seconds, 0 disables.
     * 默认 启用 inode 检测，检查周期 2 秒（低频，避免每条日志都 stat）
     * @var int
     */
    protected $handlerInodeCheckInterval = 2;

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
        ?string $channel = null,
        ?string $logFilePath = null,
        ?string $output = null,
        ?string $dateformat = null
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
     * @param int $rotateDay
     * @return void
     */
    public function setRotateDay(int $rotateDay)
    {
        $this->rotateDay = $rotateDay;
    }

    /**
     * @return void
     */
    public function enableHourly()
    {
        $this->hourly = true;
    }

    /**
     * setLogFilePath
     * @param string $logFilePath
     * @return $this
     */
    public function setLogFilePath(string $logFilePath)
    {
        $this->initDate = $this->getDate();
        $this->initDateRound = $this->getDateRound();
        $dateLogFilePath = $this->getDateLogFile($this->initDate, $logFilePath);
        $this->logFilePath = $logFilePath;
        $this->dateLogFilePath = $dateLogFilePath;
        $this->handler = new StreamHandler($this->dateLogFilePath);
        $this->handler->setReopenInterval($this->handlerReopenInterval);
        $this->handler->setInodeCheckInterval($this->handlerInodeCheckInterval);
        $this->handler->setFormatter($this->formatter);
        return $this;
    }

    /**
     * Configure stream handler periodic reopen interval.
     *
     * @param int $seconds
     * @return $this
     */
    public function setHandlerReopenInterval(int $seconds)
    {
        $this->handlerReopenInterval = max(0, $seconds);
        if ($this->handler instanceof StreamHandler) {
            $this->handler->setReopenInterval($this->handlerReopenInterval);
        }
        return $this;
    }

    /**
     * Configure stream handler inode check interval.
     *
     * @param int $seconds
     * @return $this
     */
    public function setHandlerInodeCheckInterval(int $seconds)
    {
        $this->handlerInodeCheckInterval = max(0, $seconds);
        if ($this->handler instanceof StreamHandler) {
            $this->handler->setInodeCheckInterval($this->handlerInodeCheckInterval);
        }
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
        if (!str_contains($fileInfo['dirname'], $date)) {
            $logDatePath = $fileInfo['dirname'].DIRECTORY_SEPARATOR.$date;
        }else {
            $logDatePath = $fileInfo['dirname'];
        }

        if (!is_dir($logDatePath)) {
            mkdir($logDatePath, 0777, true);
        }

        if ($this->hourly) {
            $hour = join("",[date('H', time()), 'h']);
            $fileName = $fileInfo['filename'].'-'.$hour.'.'.$fileInfo['extension'];
        }else {
            $fileName = $fileInfo['filename'].'.'.$fileInfo['extension'];
        }

        return $logDatePath.DIRECTORY_SEPARATOR.$fileName;
    }

    /**
     * @param string $logFilePath
     * @return mixed
     */
    protected function unlinkHistoryDayLogFile(string $logFilePath)
    {
        if ($this->doUnlink) {
            return;
        }

        $pattern = '/^(.*?)([\/][0-9]{8})/';
        if (preg_match($pattern, $logFilePath, $matches)) {
            $parentDir = DIRECTORY_SEPARATOR.trim($matches[1],'/');
            $logDirList = scandir($parentDir);
            $lastDay = date('Ymd', time() - 24 * 3600 * ($this->rotateDay));
            foreach ($logDirList as $logDateDir) {
                if ($logDateDir == '.' || $logDateDir == '..') {
                    continue;
                }
                $fullDateLogPath= $parentDir.DIRECTORY_SEPARATOR.$logDateDir;
                if (is_dir($fullDateLogPath) && is_numeric($logDateDir) && $logDateDir < $lastDay) {
                    if (defined('LINUX_RM_SHELL')) {
                        $shell = constant('LINUX_RM_SHELL');
                    }else {
                        $shell = 'rm -rf';
                    }
                    try {
                        @exec($shell.' '.$fullDateLogPath, $output, $return_var);
                    }catch (\Throwable $e) {
                        var_dump($e->getMessage());
                    }
                }
            }
            $this->doUnlink = true;
        }
    }

    /**
     * @return string
     */
    protected function getDate()
    {
        return date('Ymd', time());
    }

    /**
     * @return string
     */
    protected function getDateRound()
    {
        if ($this->hourly) {
            return date('Ymd-H', time());
        }else {
            return date('Ymd', time());
        }
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
        if ($this->getDateRound() != $this->initDateRound) {
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
     * @param bool $isDelayBatch
     * @param array $context
     */
    public function addInfo($logInfo, bool $isDelayBatch = false, array $context = [])
    {
        $this->handleLog(__FUNCTION__, $logInfo, $isDelayBatch, $context, Logger::INFO);
    }

    /**
     * addNotice
     * @param $logInfo
     * @param bool $isDelayBatch
     * @param array $context
     * @param \Throwable
     */
    public function addNotice($logInfo, bool $isDelayBatch = false, array $context = [])
    {
        $this->handleLog(__FUNCTION__, $logInfo, $isDelayBatch, $context, Logger::NOTICE);
    }

    /**
     * addWarning
     * @param $logInfo
     * @param bool $isDelayBatch
     * @param array $context
     */
    public function addWarning($logInfo, bool $isDelayBatch = false, array $context = [])
    {
        $this->handleLog(__FUNCTION__, $logInfo, $isDelayBatch, $context, Logger::WARNING);
    }

    /**
     * addError
     * @param $logInfo
     * @param bool $isDelayBatch
     * @param array $context
     */
    public function addError($logInfo, bool $isDelayBatch = false, array $context = [])
    {
        $this->handleLog(__FUNCTION__, $logInfo, $isDelayBatch, $context, Logger::ERROR);
    }

    /**
     * @param $function
     * @param $isDelayBatch
     * @param $logInfo
     * @param $context
     * @param $type
     * @return bool
     * @throws \Exception
     */
    protected function handleLog($function, $logInfo, $isDelayBatch, $context, $type)
    {
        if ($isDelayBatch && Swfy::isWorkerProcess()) {
            $app = Application::getApp();
            if (is_object($app)) {
                $app->setLog($this->type, $function, [$logInfo, false, $context]);
            }
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
        try {
            $dateLogFilePath = $this->getLogFilePath();
            $this->logger->setHandlers([]);
            $this->logger->pushHandler($this->handler);

            if($this->formatter instanceof JsonFormatter) {
                $this->logger->pushProcessor(function ($records) {
                    return $this->pushProcessor($records);
                });
            }
            $this->unlinkHistoryDayLogFile($dateLogFilePath);
            // add records to the log
            $this->logger->addRecord($type, $logInfo, $context);
        } catch (\Exception $e) {
            fmtPrintError('insertLog record log error: '.$e->getMessage());
        }
    }

    /**
     * 可继承重写-定义公共的字段信息
     *
     * @param array $records
     * @param $App
     * @return array
     */
    protected function pushProcessor($records): array
    {
        $App = Application::getApp();
        $records['trace_id'] = '';
        $cid = \Swoole\Coroutine::getCid();
        if ($cid >= 0) {
            if (SwooleContext::has(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID)) {
                $records['trace_id'] = SwooleContext::get(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID);
            }
        }
        $records['method'] = '';
        $records['route'] = '';
        $records['handle_class'] = (string) getenv('handle_class');
        $records['request_params'] = [];
        $records['process'] = 'task_worker|use_self_worker';
        $records['timestamp'] = microtime(true);
        $records['hostname']  = gethostname();
        $records['cid'] = $cid;
        $records['process_id'] = (int)getmypid();
        if (Swfy::isWorkerProcess()) {
            $records['process'] = 'cli_worker';
            if ($App instanceof App) {
                $requestInput = $App->requestInput;
                $records['method'] = $requestInput->getMethod();
                $records['route'] = $requestInput->getRequestUri();
                $records['request_params'] = $requestInput->getRequestParams();
            }else if ($App instanceof Swoole) {
                $records['route'] = $App->getServiceHandle();
                $records['request_params'] = $App->getMixedParams();
            }
        } else if (Swfy::isTaskProcess()) {
            $records['process'] = 'cli_task';
        } else if (Swfy::isSelfProcess()) {
            $records['process'] = 'cli_use_self_process';
        }

        if (SystemEnv::isDaemonService()) {
            $records['process'] = 'daemon';
        } else if (SystemEnv::isCronService()) {
            $records['process'] = 'cron';
        } else if (SystemEnv::isScriptService()) {
            $records['process'] = 'script';
            $records['route'] = (string) getenv('c');
        }

        return $records;
    }

    /**
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        $methodName = $this->prefix . ucfirst($method);
        return $this->$methodName(...$args);
    }

}