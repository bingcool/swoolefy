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

use Swoolefy\Exception\SystemException;

/**
 * Class EventApp
 * @package Swoolefy\Core
 * @mixin EventController
 * @inheritDoc 此类主要是框架内部使用，业务层面不要调用此类
 */
class EventApp
{

    /**
     * $eventApp 事件处理应用对象
     * @var EventController
     */
    protected $eventApp;

    /**
     * $isCall 单例是否已调用执行函数，只能调用一次执行函数，即单例只能有一个入口执行函数
     * @var bool
     */
    protected $isCall = false;

    /**
     * 是否由 run() 入口调用
     * @var bool
     */
    protected $invokedViaRun = false;

    /**
     * 单例应用对象。业务层面不要直接调用这个(框架内部使用)。业务使用goApp()来获取单协程例应用对象
     *
     * 同步/协程事件唯一运行入口：注册、执行，并在 finally 保证资源释放。
     * cid &lt; 0 时主动 end()；协程内由 EventController::defer 接管，不重复结束。
     * 可用于onConnect, onOpen, onPipeMessage,onHandShake, onClose,onFinish这些协程回调中，每次回调都会创建一个协程
     * 例如在close事件，App\AbstractEventHandle\Close业务类需要继承于\Swoolefy\Core\EventController
     *
     * 在一个协程内，只需要run()一次就好
     *
     * @param \Closure|string|object $class
     * @param array $args
     * @return EventController
     * @throws \Throwable
     */
    public static function run($class, array $args = []): EventController
    {
        $eventApp = new self();
        $eventApp->invokedViaRun = true;
        try {
            $eventApp->registerApp($class, $args);
            return $eventApp->getEventApp();
        } finally {
            // 非协程无 defer，必须在此 end；已 defer 则跳过，避免重复清理
            $controller = $eventApp->eventApp;
            if ($controller !== null && !$controller->isDefer() && !$controller->isEnd()) {
                try {
                    $controller->end();
                } catch (\Throwable $cleanupThrowable) {
                    BaseServer::catchException($cleanupThrowable);
                }
            }
        }
    }

    /**
     * registerApp 注册事件处理应用对象，注册一次处理事件
     * 可用于onConnect, onOpen, onPipeMessage,onHandShake, onClose,onFinish这些协程回调中，每次回调都会创建一个协程
     * 例如在close事件，App\AbstractEventHandle\Close业务类需要继承于\Swoolefy\Core\EventController
     *
     * 请改用 EventApp::run()，以保证同步事件也能 finally 释放 Application
     * @param \Closure|string $class
     * @param array $args
     * @return $this
     */
    protected function registerApp($class, array $args = [])
    {
        if ($class instanceof \Closure) {
            $existing = Application::getApp();
            if ($existing instanceof EventController) {
                // 同一 EventController 重复注册允许幂等复用
                $this->eventApp = $existing;
            } elseif ($existing !== null) {
                // 已绑定 HTTP App 等时禁止覆盖，提示使用 goApp()
                throw new SystemException(sprintf(
                    'Application already bound as %s in current coroutine; use goApp() instead of creating EventApp',
                    get_class($existing)
                ));
            } else {
                $this->eventApp = new EventController(...$args);
            }

            call_user_func($class, $this->eventApp);

        } else {
            if (is_string($class)) {
                $this->eventApp = new $class(...$args);
            } else if (is_object($class)) {
                $this->eventApp = $class;
            }

            if (!($this->eventApp instanceof EventController)) {
                $className = get_class($this->eventApp);
                unset($this->eventApp);
                throw new SystemException(
                    sprintf(
                    "%s must extends \Swoolefy\Core\EventController, please check it",
                    $className
                ));
            }
        }

        return $this;
    }

    /**
     * getCid
     * @return int
     */
    public function getCid(): int
    {
        return $this->eventApp->getCid();
    }

    /**
     * @return EventController
     */
    public function getEventApp(): EventController
    {
        return $this->eventApp;
    }

    /**
     * __call 在协程编程中可直接使用try/catch处理异常,但必须在协程内捕获,不得跨协程捕获异常。
     * 当协程退出时，发现有未捕获的异常，将引起致命错误.
     * @param string $action
     * @param array $args
     * @return mixed
     */
    public function __call(string $action, array $args = [])
    {
        if ($this->isCall && \Swoole\Coroutine::getCid() > 0) {
            $className = get_class($this->eventApp);
            throw new SystemException(sprintf("%s Single Coroutine Instance only be called one method, you had called", $className));
        }
        $this->isCall = true;
        return $this->eventApp->$action(...$args);
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        if (is_object($this->eventApp) && $this->eventApp instanceof EventController) {
            unset($this->eventApp);
        }
    }
}
