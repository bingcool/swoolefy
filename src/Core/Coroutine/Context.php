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

namespace Swoolefy\Core\Coroutine;

use ArrayObject;
use Swoolefy\Core\Application;
use Swoolefy\Exception\ContextUnavailableException;
use Swoolefy\Exception\InvalidContextValueException;

/**
 * 如果需要在父协程->子协程->子协程 直接透传业务的关键数据，请使用 @see \Swoolefy\Core\Coroutine\Context::set() 设置,
 * 然后使用 @see \Swoolefy\Core\Coroutine\Context::get()
 *
 * 可传播值仅限 scalar、array、null；不得把连接、SDK Client、Closure 或业务对象快照到子协程和异步任务。
 */
class Context
{
    /**
     * @return ArrayObject|null 无协程且无 Application 时返回 null，不再回退进程级静态共享区
     */
    public static function getContext(): ?ArrayObject
    {
        if (\Swoole\Coroutine::getCid() >= 0) {
            $context = \Swoole\Coroutine::getContext();
            return $context;
        }

        $app = Application::getApp();
        if (is_object($app)) {
            if ($app->isSetContext()) {
                return $app->getContext();
            }
            $context = new ArrayObject();
            $context->setFlags(ArrayObject::STD_PROP_LIST | ArrayObject::ARRAY_AS_PROPS);
            $app->setContext($context);
            return $context;
        }

        return null;
    }

    /**
     * 读取可传播协程上下文数据快照；无协程且无 Application 时返回空数组，避免 TypeError。
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $context = self::getContext();
        if ($context === null) {
            return [];
        }

        return self::filterPropagatable($context->getArrayCopy());
    }

    /**
     * 递归校验仅允许 scalar、array、null；对象/资源/Closure 抛出（错误信息只含键名与类型）。
     *
     * @param array<string|int, mixed> $data
     */
    public static function assertPropagatable(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $keyPath = $path === '' ? (string) $key : $path . '.' . $key;
            if (is_array($value)) {
                self::assertPropagatable($value, $keyPath);
                continue;
            }
            if ($value === null || is_scalar($value)) {
                continue;
            }
            throw new InvalidContextValueException(sprintf(
                'Context key "%s" has non-propagatable type %s',
                $keyPath,
                get_debug_type($value)
            ));
        }
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return bool
     * @throws ContextUnavailableException
     */
    public static function set(string $name, $value): bool
    {
        $context = self::getContext();
        // 无有效容器时拒绝写入，避免进程级串数据
        if ($context === null) {
            throw new ContextUnavailableException(
                'Context is unavailable: no coroutine context and no Application bound; refuse to write process-global shared storage'
            );
        }
        $context[$name] = $value;
        return true;
    }

    /**
     * @param string $name
     * @param mixed $default 无上下文或键不存在时返回
     * @return mixed
     */
    public static function get(string $name, $default = null)
    {
        $context = self::getContext();
        if ($context === null) {
            return $default;
        }
        return $context[$name] ?? $default;
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function delete(string $name): bool
    {
        $context = self::getContext();
        // 无上下文时幂等返回
        if ($context === null) {
            return true;
        }
        unset($context[$name]);
        return true;
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function has(string $name): bool
    {
        $context = self::getContext();
        if ($context === null) {
            return false;
        }
        return isset($context[$name]);
    }

    /**
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    protected static function filterPropagatable(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::filterPropagatable($value);
            } elseif ($value === null || is_scalar($value)) {
                $out[$key] = $value;
            }
            // 对象/资源/Closure 不进入快照，防止子协程反向污染或序列化失败
        }
        return $out;
    }
}
