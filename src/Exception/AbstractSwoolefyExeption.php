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

namespace Swoolefy\Exception;

class AbstractSwoolefyExeption extends \Exception
{
    /**
     * @var array
     */
    protected $contextData = [];

    /**
     * @param string $message
     * @param int $code
     * @param array $contextData
     * @param \Throwable|null $previous
     * @return void
     */
    public static function throw(string $message, array $contextData, int $code = 0, ?\Throwable $previous = null)
    {
        $throw = new static($message, $code, $previous);
        $throw->contextData = $contextData;
        throw $throw;
    }

    /**
     * @return array
     */
    public function getContextData()
    {
        return $this->contextData;
    }
}