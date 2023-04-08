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

namespace Swoolefy\Core;

use Swoolefy\Core\Coroutine\CoroutineManager;
use Swoolefy\Exception\SystemException;

/**
 * Class EventController
 * @package Swoolefy\Core
 */
class EventController extends BaseObject
{

    use \Swoolefy\Core\ComponentTrait, \Swoolefy\Core\ServiceTrait;

    /**
     * $appConf
     * @var array
     */
    protected $appConf = [];

    /**
     * @var array
     */
    protected $logs = [];

    /**
     * $is_end
     * @var bool
     */
    protected $isEnd = false;

    /**
     * $is_defer
     * @var bool
     */
    protected $isDefer = false;

    /**
     * $eventHooks
     * @var array
     */
    protected $eventHooks = [];

    /**
     * after request event
     */
    const HOOK_AFTER_REQUEST = 1;

    /**
     * __construct
     * @throws \Exception
     */
    public function __construct(...$args)
    {
        $this->creatObject();
        $this->appConf = Swfy::getAppConf();
        $this->coroutineId = CoroutineManager::getInstance()->getCoroutineId();
        Application::setApp($this);
        $this->defer();
    }

    /**
     * setApp
     * @param int $coroutineId
     * @return bool
     */
    public function setApp(int $coroutineId): bool
    {
        if ($coroutineId) {
            Application::removeApp($this->coroutineId);
            $this->coroutineId = $coroutineId;
            Application::setApp($this);
            return true;
        }

        return false;
    }

    /**
     * afterRequest
     * @param callable $callback
     * @param bool $prepend
     * @return bool
     */
    public function afterRequest(callable $callback, bool $prepend = false)
    {
        if (is_callable($callback, true, $callableName)) {
            $key = md5($callableName);
            if ($prepend) {
                if (!isset($this->eventHooks[self::HOOK_AFTER_REQUEST])) {
                    $this->eventHooks[self::HOOK_AFTER_REQUEST] = [];
                }
                if (!isset($this->eventHooks[self::HOOK_AFTER_REQUEST][$key])) {
                    $this->eventHooks[self::HOOK_AFTER_REQUEST][$key] = array_merge([$key => $callback], $this->eventHooks[self::HOOK_AFTER_REQUEST]);
                }
            } else {
                if (!isset($this->eventHooks[self::HOOK_AFTER_REQUEST][$key])) {
                    $this->eventHooks[self::HOOK_AFTER_REQUEST][$key] = $callback;
                }
            }
            return true;
        }
    }

    /**
     * callEventHook
     * @return void
     */
    public function callAfterEventHook()
    {
        if (isset($this->eventHooks[self::HOOK_AFTER_REQUEST]) && !empty($this->eventHooks[self::HOOK_AFTER_REQUEST])) {
            foreach ($this->eventHooks[self::HOOK_AFTER_REQUEST] as $func) {
                try {
                    $func();
                }catch (\Throwable $exception) {

                }
            }
        }
    }

    /**
     * beforeAction 在处理实际action之前执行
     * EventController不会执行该动作,所以继承于EventController其他类不要调用该method
     * @return bool
     */
    public function _beforeAction()
    {
        return true;
    }

    /**
     * afterAction
     * @return void
     */
    public function _afterAction()
    {

    }

    /**
     * setEnd
     * @return void
     */
    public function setEnd()
    {
        $this->isEnd = true;
    }

    /**
     * @return bool
     */
    public function isEnd()
    {
        return $this->isEnd;
    }

    /**
     * defer
     * @return void
     */
    protected function defer()
    {
        if (\Swoole\Coroutine::getCid() >= 0) {
            $this->isDefer = true;
            \Swoole\Coroutine::defer(function () {
                $this->end();
            });
        }
    }

    /**
     * @return bool
     */
    public function isDefer()
    {
        return $this->isDefer;
    }

    /**
     * end unset var
     * @return bool|void
     */
    public function end()
    {
        if ($this->isEnd) {
            return true;
        }
        // set End
        $this->setEnd();
        // call hook callable
        static::_afterAction();
        // callHooks
        $this->callAfterEventHook();
        // handle log
        $this->handleLog();
        // remove
        ZFactory::removeInstance();
        // push obj pools
        $this->pushComponentPools();
        // remove App Instance
        Application::removeApp($this->coroutineId);
    }

}