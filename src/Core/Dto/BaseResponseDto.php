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

class BaseResponseDto extends \stdClass
{
    /**
     * @var int
     */
    public $code;

    /**
     * @var string
     */
    public $msg = '';

    /**
     * @var array
     */
    public $data = [];

    /**
     * toArray
     */
    public function toArray()
    {
        return (array)$this;
    }
}