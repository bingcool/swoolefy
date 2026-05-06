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

namespace Swoolefy\Core\Dto;

class AbstractDto extends ArrayDto
{
    /**
     * __set
     * @param  string $name
     * @param  mixed $value
     */
    public function __set(string $name, $value)
    {
        $this->$name = $value;
    }

    /**
     * __get
     * @param  string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->$name ?? null;
    }
}