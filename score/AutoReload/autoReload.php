<?php
namespace Swoolefy\AutoReload;

use Swoole\Process as swoole_process;
use Swoolefy\Tool\Log;
use Swoolefy\Tool\Swiftmail;
use Exception;

class autoReload {
	/**
	 * $inotify
	 * @var null
	 */
	private $inotify = null;

	/**
	 * $swoole_pid,swoole服务的主id
	 * @var null
	 */
	private $swoole_pid = null;

	/**
	 * $reloadFileTypes,定义哪些文件的改动将触发swoole服务重启
	 * @var array
	 */
	protected $reloadFileTypes = ['.php','.html','.js'];

	/**
	 * $watchFiles,保存监听的文件句柄
	 * @var array
	 */
    protected $watchFiles = [];

    /**
     * $afterNSeconds,等待的时间间隔重启
     * @var integer
     */
    public $afterNSeconds = 10;

    /**
     * $process_pid，创建的多进程的id
     * @var null
     */
    public $process_pid = null;

    /**
     * $reloading,默认是不重启的
     * boolean
     */
    protected $reloading = false;

    /**
     * $events,默认监听的事件类型
     * @var [type]
     */
    protected $events = IN_MODIFY | IN_DELETE | IN_CREATE | IN_MOVE;

    /**
     * 根目录
     * @var array
     */
    protected $rootDirs = [];

    /**
     * $isOnline,默认打印在屏幕上，线上建议将日志写入文件
     * @var boolean
     */
    public $isOnline = false;

    /**
     * $isEmail,监听到swoole是否发邮件通知运维人员
     * @var boolean
     */
    public $isEmail = false;

    /**
     * $logDir,inotify重启的的错误日志记录
     * @var [type]
     */
    public $logFilePath = __DIR__."/inotify.log";

    /**
     * $Log
     * @var null
     */
    private $log = null;

    /**
     * $monitorShellFile
     * @var [type]
     */
    public $monitorShellFile = __DIR__."/../Shell/swoole_monitor.sh";

    /**
     * $monitorPort
     * @var [type]
     */
    public $monitorPort = 9501;
    /**
     * $logChannel，日志显示的频道主题
     * @var string
     */
    public $logChannel = 'inotifyMonitor';

    /**
     * $smtpTransport，邮件的代理服务信息
     * @var string
     */
    public $smtpTransport = null;

    /**
     * $smtpTransport，邮件的发送的msg信息
     * @var string
     */
    public $message = null;

    /**
     * __construct
     */
    public function __construct() {
        //初始化inotify的句柄 
        $this->inotify = inotify_init();  
    }    
    /**
     * init
     * @return [type] [description]
     */
    public function init() {
        // 判断是否初始inotify
        !$this->inotify && $this->inotify = inotify_init();
        // Log对象,线上模式才创建
        if($this->isOnline) {
            $this->log = new Log($this->logChannel,$this->logFilePath); 
        }
    	// 将inotify添加至异步事件的eventloop
    	swoole_event_add($this->inotify, function ($fd) {
    		// 读取事件的信息
            $events = inotify_read($this->inotify);

            if (!$events)
            {
                return;
            }
            // 只要检测到一个文件改动，则停止其余文件的判断，等待时间重启即可
            if (!$this->reloading)
            {
                foreach($events as $ev)
                {
                    if ($ev['mask'] == IN_IGNORED)
                    {
                        continue;

                    }else if ($ev['mask'] == IN_CREATE || $ev['mask'] == IN_DELETE || $ev['mask'] == IN_MODIFY || $ev['mask'] == IN_MOVED_TO || $ev['mask'] == IN_MOVED_FROM)
                    {
                        // 获取改动文件文件的后缀名
                        $fileType = '.'.pathinfo($ev['name'], PATHINFO_EXTENSION);
                        //非重启类型
                        if(!in_array($fileType, $this->reloadFileTypes))
                        {
                            continue;
                        }
                    }
                    //正在reload，不再接受任何事件，冻结10秒
                    if (!$this->reloading)
                    {   
                        try {
                            $process = new swoole_process(function($process_worker){
                                $process_worker->exec('/bin/bash', array($this->monitorShellFile,$this->monitorPort)); 
                            }, true);

                            $process_pid = $process->start();
                            $this->swoole_pid = intval($process->read());
                            swoole_process::wait();

                            if(!is_int($this->swoole_pid) || !$this->swoole_pid) {
                                // 线上记录日志模式和调试模式
                                $this->putLog("swoole已经停止....,请手动启动swoole!",'error');
                                $this->sendEmail([
                                    "subject"=>"检测到swoole已停止",
                                    "body"   =>"swoole可能发送错误已经停止，请手动启动"
                                ]);
                                return;
                            }

                        }catch(Exception $e) {
                            // 线上环境这里可以写发邮件通知
                            $this->putLog("无法检测swoole_pid，无法重启",'error');
                            $this->sendEmail([
                                "subject"=>"检测到swoole已停止",
                                "body"   =>"swoole可能发送错误已经停止，请手动启动"
                            ]);
                            return;
                        }

                        // 调试模式，打印信息在终端,线上模式将会写进日志文件
                        $this->putLog("after ".$this->afterNSeconds." seconds reload the server",'info');
                        //有事件发生了，进行重启
                        swoole_timer_after($this->afterNSeconds * 1000, [$this, 'reload']);
                        $this->reloading = true;
                    }
                }
            }
        });
    }

    /**
     * putLog
     * @param  $log
     * @param  $tag
     */
    public function putLog($logInfo,$tag='error') {
    	if($this->isOnline) {
    		// 线上模式
			// add records to the log
            switch ($tag) {
                case 'info': $this->log->addInfo($logInfo);
                break;
			    case 'notice': $this->log->addNotice($logInfo);
                break;
                case 'warning': $this->log->addWarning($logInfo);
                break;
                case 'error': $this->log->addError($logInfo);
                break;
                default:break;
            }
    	}else {
    		// 调试模式
    		$_log = "[".date('Y-m-d H:i:s')."]\t".$tag.':'.$logInfo."\n";
        	echo $_log;
    	}
    	
    }

     /**
     * sendEmail
     * @param  $subject
     * @param  $body
     */
    public function sendEmail(array $msg) {
        if($this->isOnline) {
            // 根据需要可能在不同的场景下发送不同的邮件主题以及通知内容信息，所以这里设置$msg变量
            // 邮件发送是一个耗时过程，fork一个进程专门处理邮件发送业务
            $process_mail = new swoole_process(function($process_mail_worker) use($msg) {
                $swiftmail = new Swiftmail();
                // 初始化信息
                $swiftmail->setSmtpTransport($this->smtpTransport);
                $swiftmail->setMessage($this->message);
                // 重新设置覆盖原来的主题信息
                isset($msg['subject']) && $swiftmail->setSubject($msg['subject']);
                // 重新设置覆盖原来的body信息
                isset($msg['body']) && $swiftmail->setBody($msg['body']);
                // 覆盖
                isset($msg['from']) && $swiftmail->setFrom($msg['from']);
                // 覆盖
                isset($msg['to']) && $swiftmail->setTo($msg['to']);
                // 覆盖
                isset($msg['attach']) && $swiftmail->setAttach($msg['attach']);
                // 发送邮件
                $swiftmail->sendEmail();
            }, true);
            // fork进程
            $process_mail_pid = $process_mail->start();
            // 回收进程
            swoole_process::wait();
            return;
        }
        return;
    }

    /**
     * 重启
     * @return [type] [description]
     */
    public function reload() {
        // 调试模式，打印信息在终端
        $this->putLog("reloading",'info');
        //向主进程发送信号
        posix_kill($this->swoole_pid, SIGUSR1);
        //清理所有监听
        $this->clearWatch();
        //重新监听
        foreach($this->rootDirs as $root)
        {
            $this->watch($root);
        }
        //继续进行reload
        $this->reloading = false;
        // 重置为null
        $this->swoole_pid = null;
    }

    /**
     * 添加文件类型
     * @param $type
     */
    public function addFileType($type) {
        $type = trim($type, '.');
        $fileType = '.'.$type;
        array_push($this->reloadFileTypes,$fileType);
    }

    /**
     * 添加事件
     * @param $inotifyEvent
     */
    public function addEvent($inotifyEvent) {
        $this->events |= $inotifyEvent;
    }

    /**
     * 清理所有inotify监听
     */
    private function clearWatch() {
        foreach($this->watchFiles as $wd)
        {
            // 忽略返回的警告信息
            @inotify_rm_watch($this->inotify, $wd);
        }
        $this->watchFiles = [];
    }


    /**
     * @param $dir
     * @param bool $root
     * @return bool
     * @throws NotFound
     */
    public function watch($dir, $root = true) {
        //目录不存在
        if (!is_dir($dir))
        {
            $error = "[$dir] is not a directory.";
            throw new \Exception ($error);
        }
        //避免重复监听
        if (isset($this->watchFiles[$dir]))
        {
            return false;
        }
        //根目录
        if ($root)
        {
            $this->rootDirs[] = $dir;
        }

        $wd = inotify_add_watch($this->inotify, $dir, $this->events);
        $this->watchFiles[$dir] = $wd;

        $files = scandir($dir);
        foreach ($files as $f)
        {
            if ($f == '.' || $f == '..')
            {
                continue;
            }
            $path = $dir . '/' . $f;
            //递归目录
            if (is_dir($path))
            {
                $this->watch($path, false);
            }
            //检测文件类型
            $fileType = '.'.pathinfo($f, PATHINFO_EXTENSION);

            if(in_array($fileType, $this->reloadFileTypes))
            {
                $wd = inotify_add_watch($this->inotify, $path, $this->events);
                $this->watchFiles[$path] = $wd;
            }
        }
        return true;
    }

    public function run() {
        swoole_event_wait();
    }
}