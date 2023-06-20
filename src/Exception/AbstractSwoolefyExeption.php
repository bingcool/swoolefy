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

class AbstractSwoolefyExeption extends \RuntimeException
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
     * @return mixed
     * @throws AbstractSwoolefyExeption
     */
    public static function throw(string $message, int $code = -1, array $contextData = [], ?\Throwable $previous = null)
    {
        $throw = new static($message, $code, $previous);
        $throw->setContextData($contextData);
        return $throw;
    }

    /**
     * @param array $contextData
     * @return void
     */
    public function setContextData(array $contextData)
    {
        $this->contextData = $contextData;
    }

    /**
     * @return array
     */
    public function getContextData()
    {
        return $this->contextData;
    }
}