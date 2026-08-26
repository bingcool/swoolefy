<?php
namespace Test\Module\Cron\Entity;

use Swoolefy\Library\Db\Concern\SoftDelete;
use Swoolefy\Library\Db\Query;
use Test\Model\ClientModel;

// 生成的表【cron_task】的属性
/**
 * @property int id
 * @property int node_id 节点ID
 * @property string cron_name 任务名称
 * @property string expression cron表达式
 * @property string command 执行命令
 * @property int exec_type 执行类型 1-shell，2-http
 * @property int status 状态 0-禁用，1-启用
 * @property int with_block_lapping 是否阻塞执行 0-否，1->是
 * @property int retry 失败后重试次数（不含首次；0=不重试）
 * @property string description 描述
 * @property string cron_between json类型-允许执行时间段
 * @property string cron_skip json类型-不允许执行时间段(即需跳过的时间段)
 * @property string http_method http请求方法
 * @property string http_body json类型-http请求体
 * @property string http_headers json类型-http请求头
 * @property int http_request_time_out http请求超时时间，单位：秒
 * @property string created_at 创建时间
 * @property string updated_at 修改时间
 * @property string deleted_at 删除时间
 */

class CronTaskEntity extends ClientModel
{
    use SoftDelete;
    use CronTaskEventTrait;

    /**
     * @var string
     */
    protected static $table = 'cron_task';

    /**
     * @var string
     */
    protected $pk = 'id';

    protected $casts = [
        'cron_between' => 'array',
        'cron_skip'    => 'array',
        'http_body'    => 'array',
        'http_headers' => 'array',
    ];

    /**
     * 管理端列表 / Worker 拉取用：排除已软删行。
     *
     * Query::select() 不会自动加 SoftDelete 条件（只有 first()/loadOne 会）。
     * 若列表直接 query()->select()，已删行仍会展示，而 delete 的 loadById 因
     * `deleted_at IS NULL` 找不到，表现为「任务不存在」但列表还在。
     */
    public static function queryNotDeleted(): Query
    {
        return static::query()->whereDeletedAtNull(static::getSoftDeleteField());
    }

    /**
     * @param int|string $id cron_task 主键（请求里可能是数字字符串）
     */
    public function loadById($id): ?static
    {
        return $this->loadOne([
            'id' => (int) $id,
        ]);
    }
}