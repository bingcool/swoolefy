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

namespace Swoolefy\Support\Kubernetes;

use Swoolefy\Exception\AbstractSwoolefyExeption;

/**
 * Deployment 模板无法派生成 Job（缺 template、找不到目标容器等）。
 *
 * 这是配置 / 模板问题，不是 API 传输失败，因此不走 {@see ApiException}。
 */
class JobTemplateException extends AbstractSwoolefyExeption
{
}
