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

namespace Swoolefy\Worker\Dto;

class CronUrlTaskMetaDto extends AbstractDto
{
    /**
     * 计划任务名称
     *
     * @var string
     */
    public $cron_name = '';

    /**
     * 计划任务表达式
     * @var string
     */
    public $cron_expression = '';

    /**
     * @var string
     */
    public $url = '';

    /**
     * @var string
     */
    public $method = 'get';

    /**
     * 连接超时设置
     *
     * @var int
     */
    public $connect_time_out = 30;

    /**
     * 整个请求等待超时设置，要比connection_time_out大
     * @var int
     */
    public $request_time_out = 60;

    /**
     * 请求头
     */
    public $headers = [];

    /**
     * @var array
     */
    public $options = [];

    /**
     * get|post参数
     *
     * @var array
     */
    public $params = [];

    /**
     *
     * @var callable
     */
    public $before_callback = '';

    /**
     * @var callable
     */
    public $response_callback = '';

    /**
     * @var callable
     */
    public $after_callback = '';

    /**
     *
     * @param array $taskItem
     * @return self
     */
    public static function load(array $taskItem)
    {
        $scheduleTask = new self();
        foreach ($taskItem as $property => $value) {
            $scheduleTask->$property = $value;
        }
        return $scheduleTask;
    }
}