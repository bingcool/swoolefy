<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\AutoReload;

use Swoolefy\Core\Swfy;

class Reload {
	/**
	 * $inotify
	 * @var null
	 */
	private $inotify = null;

	/**
	 * $reloadFileTypes,定义哪些文件的改动将触发swoole服务重启
	 * @var array
	 */
	protected $reloadFileTypes = ['.php','.html','.js'];

    /**
     * $ignoreDir 忽略不需要检查的文件夹，默认vendor
     * @var array
     */
    protected $ignoreDirs = [];

	/**
	 * $watchFiles,保存监听的文件句柄
	 * @var array
	 */
    protected $watchFiles = [];

    /**
     * $afterSeconds,等待的时间间隔重启
     * @var integer
     */
    protected $afterSeconds = 10;

    /**
     * $reloading,默认是不重启的
     * boolean
     */
    protected $reloading = false;

    /**
     * $events,默认监听的事件类型
     * @var
     */
    protected $events = IN_MODIFY | IN_DELETE | IN_CREATE | IN_MOVE;

    /**
     * $rootDirs 根目录
     * @var array
     */
    protected $rootDirs = [];

    /**
     * @var
     */
    protected $callback;

    /**
     * __construct
     * @throws \Exception
     */
    public function __construct() {
        if(extension_loaded('inotify')) {
            $this->inotify = inotify_init();
        }else {
            throw new \Exception("If you want to use auto reload，you should install inotify extension,please view 'http://pecl.php.net/package/inotify'");
        }
    }    
    /**
     * init
     * @throws \Exception
     * @return $this
     */
    public function init() {
        !$this->inotify && $this->inotify = inotify_init();
    	// 将inotify添加至异步事件的eventloop
    	swoole_event_add($this->inotify, function($fd) {
    		// 读取事件的信息
            $events = inotify_read($this->inotify);
            if(!$events) {
                return;
            }
            // 只要检测到一个文件改动，则停止其余文件的判断，等待时间重启即可
            if(!$this->reloading) {
                foreach($events as $ev)
                {
                    if ($ev['mask'] == IN_IGNORED)
                    {
                        continue;

                    }else if(in_array($ev['mask'], [IN_CREATE, IN_DELETE, IN_MODIFY, IN_MOVED_TO, IN_MOVED_FROM])) {
                        // 获取改动文件文件的后缀名
                        $fileType = '.'.pathinfo($ev['name'], PATHINFO_EXTENSION);
                        
                        if(!in_array($fileType, $this->reloadFileTypes))
                        {
                            continue;
                        }
                    }
                    //正在reload，不再接受任何事件，冻结10秒
                    if (!$this->reloading)
                    {
                        //进行重启
                        swoole_timer_after($this->afterSeconds * 1000, [$this, 'reload']);
                        $this->reloading = true;
                    }
                }
            }
        });

    	return $this;
    }

    /**
     * 重启
     * @return void
     */
    protected function reload() {
        Swfy::getServer()->reload();
        //清理所有监听
        $this->clearWatch();
        //重新监听
        foreach($this->rootDirs as $root)
        {
            $this->watch($root);
        }
        //继续进行reload
        $this->reloading = false;
        $isReloadSuccess = !$this->reloading;
        if($this->callback instanceof \Closure) {
            $this->callback->call($this, $isReloadSuccess);
        }
    }

    /**
     * @param  callable $callback
     * @return $this
     */
    public function onReload(callable $callback) {
        $this->callback = $callback;
        return $this;
    }

    /**
     * addFileType 添加文件类型
     * @param $type
     * @return $this
     */
    public function addFileType(string $type) {
        $type = trim($type, '.');
        $fileType = '.'.$type;
        array_push($this->reloadFileTypes, $fileType);
        return $this;
    }

    /**
     * addEvent 添加事件
     * @param  $inotifyEvent
     * @return $this
     */
    public function addEvent($inotifyEvent) {
        $this->events |= $inotifyEvent;
        return $this;
    }

    /**
     * clearWatch 清理所有inotify监听
     * @return $this
     */
    private function clearWatch() {
        foreach($this->watchFiles as $wd)
        {
            @inotify_rm_watch($this->inotify, $wd);
        }
        $this->watchFiles = [];
        return $this;
    }


    /**
     * watch 监听文件目录
     * @param $dir
     * @param bool $root
     * @throws \Exception
     * @return $this
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
            return $this;
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
            if ($f == '.' || $f == '..' || in_array($f, $this->ignoreDirs))
            {
                continue;
            }
            $path = $dir . '/' . $f;
            //递归目录
            if (is_dir($path))
            {
                $this->watch($path, false);
            }
            $fileType = '.'.pathinfo($f, PATHINFO_EXTENSION);

            if(in_array($fileType, $this->reloadFileTypes))
            {
                $wd = inotify_add_watch($this->inotify, $path, $this->events);
                $this->watchFiles[$path] = $wd;
            }
        }
        return $this;
    }

    /**
     * setAfterNSeconds 启动停顿时间
     * @param float $seconds
     * @return $this
     */
    public function setAfterSeconds(float $seconds = 10) {
        $this->afterSeconds = $seconds;
        return $this;
    }

    /**
     * setReoloadFileType
     * @param array $file_type
     * @return $this
     */
    public function setReoloadFileType(array $file_type = ['.php']) {
        $this->reloadFileTypes = array_merge($this->reloadFileTypes, $file_type);
        return $this;
    }

    /**
     * setIgnoreDir 设置忽略不需检测的文件夹
     * @param array $ignore_dir
     * @return $this
     */
    public function setIgnoreDirs(array $ignore_dirs = []) {
        $this->ignoreDirs = array_merge($this->ignoreDirs, $ignore_dirs);
        return $this;
    }
}