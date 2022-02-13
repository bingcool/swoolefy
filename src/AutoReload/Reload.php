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

namespace Swoolefy\AutoReload;

use Swoolefy\Core\Swfy;

class Reload
{
    /**
     * $inotify
     * @var null
     */
    private $inotify = null;

    /**
     * $reloadFileTypes 定义触发swoole服务重启文件的类型
     * @var array
     */
    protected $reloadFileTypes = ['.php', '.html', '.js'];

    /**
     * $ignoreDir 忽略不需要检查的文件夹，默认vendor
     * @var array
     */
    protected $ignoreDirs = [];

    /**
     * $watchFiles 保存监听的文件句柄
     * @var array
     */
    protected $watchFiles = [];

    /**
     * $afterSeconds 等待的时间间隔重启
     * @var integer
     */
    protected $afterSeconds = 10;

    /**
     * $reloading 默认是不重启的
     * boolean
     */
    protected $reloading = false;

    /**
     * $events 默认监听的事件类型
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
    public function __construct()
    {
        if (!extension_loaded('inotify')) {
            throw new \Exception("If you want to use auto reload，you should install inotify extension,please view 'http://pecl.php.net/package/inotify'");
        }
        $this->inotify = inotify_init();
    }

    /**
     * init
     * @return $this
     * @throws \Exception
     */
    public function init()
    {
        !$this->inotify && $this->inotify = inotify_init();
        \Swoole\Event::add($this->inotify, function ($fd) {
            $events = inotify_read($this->inotify);
            if (!$events) {
                return;
            }
            //只要检测到一个文件改动，则停止其余文件的判断，等待时间重启即可
            if (!$this->reloading) {
                foreach ($events as $ev) {
                    if ($ev['mask'] == IN_IGNORED) {
                        continue;
                    } else if (in_array($ev['mask'], [IN_CREATE, IN_DELETE, IN_MODIFY, IN_MOVED_TO, IN_MOVED_FROM])) {
                        $fileType = '.' . pathinfo($ev['name'], PATHINFO_EXTENSION);

                        if (!in_array($fileType, $this->reloadFileTypes)) {
                            continue;
                        }
                    }
                    //正在reload，不再接受任何事件，冻结10秒
                    if (!$this->reloading) {
                        \Swoole\Timer::after($this->afterSeconds * 1000, [$this, 'reload']);
                        $this->reloading = true;
                    }
                }
            }
        });

        return $this;
    }

    /**
     * reboot
     * @return void
     */
    protected function reload()
    {
        Swfy::getServer()->reload();
        //clear listen
        $this->clearWatch();
        //re listen
        foreach ($this->rootDirs as $root) {
            $this->watch($root);
        }
        //reloading
        $this->reloading = false;
        $isReloadSuccess = !$this->reloading;
        if ($this->callback instanceof \Closure) {
            $this->callback->call($this, $isReloadSuccess);
        }
    }

    /**
     * @param callable $callback
     * @return $this
     */
    public function onReload(callable $callback)
    {
        $this->callback = $callback;
        return $this;
    }

    /**
     * addFileType 添加文件类型
     * @param $type
     * @return $this
     */
    public function addFileType(string $type)
    {
        $type = trim($type, '.');
        $fileType = '.' . $type;
        array_push($this->reloadFileTypes, $fileType);
        return $this;
    }

    /**
     * addEvent 添加事件
     * @param  $inotifyEvent
     * @return $this
     */
    public function addEvent($inotifyEvent)
    {
        $this->events |= $inotifyEvent;
        return $this;
    }

    /**
     * clearWatch 清理所有inotify监听
     * @return $this
     */
    private function clearWatch()
    {
        foreach ($this->watchFiles as $wd) {
            @inotify_rm_watch($this->inotify, $wd);
        }
        $this->watchFiles = [];
        return $this;
    }

    /**
     * watch 监听文件目录
     * @param $dir
     * @param bool $root
     * @return $this
     * @throws \Exception
     */
    public function watch($dir, bool $root = true)
    {
        if (!is_dir($dir)) {
            throw new \Exception("[$dir] is not a directory.");
        }
        //避免重复监听
        if (isset($this->watchFiles[$dir])) {
            return $this;
        }
        //根目录
        if ($root) {
            $this->rootDirs[] = $dir;
        }

        $wd = inotify_add_watch($this->inotify, $dir, $this->events);
        $this->watchFiles[$dir] = $wd;

        $files = scandir($dir);
        foreach ($files as $f) {
            if ($f == '.' || $f == '..' || in_array($f, $this->ignoreDirs)) {
                continue;
            }
            $path = $dir . '/' . $f;
            //递归目录
            if (is_dir($path)) {
                $this->watch($path, false);
            }
            $fileType = '.' . pathinfo($f, PATHINFO_EXTENSION);

            if (in_array($fileType, $this->reloadFileTypes)) {
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
    public function setAfterSeconds(float $seconds = 10)
    {
        $this->afterSeconds = $seconds;
        return $this;
    }

    /**
     * setReloadFileType
     * @param array $file_type
     * @return $this
     */
    public function setReloadFileType(array $file_type = ['.php'])
    {
        $this->reloadFileTypes = array_merge($this->reloadFileTypes, $file_type);
        return $this;
    }

    /**
     * setIgnoreDir 设置忽略不需检测的文件夹
     * @param array $ignore_dir
     * @return $this
     */
    public function setIgnoreDirs(array $ignore_dirs = [])
    {
        $this->ignoreDirs = array_merge($this->ignoreDirs, $ignore_dirs);
        return $this;
    }
}