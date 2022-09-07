<?php
/**
 * +----------------------------------------------------------------------
 * | Daemon and Cli model about php process worker
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Workerfy\Dto;

class MessageDto extends AbstractDto
{
    /**
     * @var string
     */
    public $fromProcessName;

    /**
     * @var int
     */
    public $fromProcessWorkerId;

    /**
     * @var string
     */
    public $toProcessName;

    /**
     * @var int
     */
    public $toProcessWorkerId;

    /**
     * @var bool
     */
    public $isProxy = true;

    /**
     * @var mixed
     */
    public $data;

}