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
     * $reloadFileTypes watch file type
     * @var array
     */
    protected $reloadFileTypes = ['.php', '.html', '.js'];

    /**
     * $ignoreDir ignore dirs
     * @var array
     */
    protected $ignoreDirs = [];

    /**
     * $watchFiles file fd
     * @var array
     */
    protected $watchFiles = [];

    /**
     * $afterSeconds reboot time
     * @var integer
     */
    protected $afterSeconds = 10;

    /**
     * $reloading
     * boolean
     */
    protected $reloading = false;

    /**
     * listen events
     * @var
     */
    protected $events = IN_MODIFY | IN_DELETE | IN_CREATE | IN_MOVE;

    /**
     * $rootDirs
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
     * addFileType
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
     * addEvent
     * @param  $inotifyEvent
     * @return $this
     */
    public function addEvent($inotifyEvent)
    {
        $this->events |= $inotifyEvent;
        return $this;
    }

    /**
     * clearWatch clear watch file
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
     * watch listen dir
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
        // ignore again
        if (isset($this->watchFiles[$dir])) {
            return $this;
        }

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
     * setAfterSeconds
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
     * setIgnoreDir
     * @param array $ignore_dir
     * @return $this
     */
    public function setIgnoreDirs(array $ignore_dirs = [])
    {
        $this->ignoreDirs = array_merge($this->ignoreDirs, $ignore_dirs);
        return $this;
    }
}