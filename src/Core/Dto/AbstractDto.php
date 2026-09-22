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

/**
 * 业务 DTO 基类。数组访问 / JSON / 迭代由 {@see ArrayDto} + {@see Concerns\InteractsWithDtoArrayAccess} 提供：
 * `$dto->field`、`$dto['field']`、`json_encode($dto)`、`foreach ($dto as $k => $v)`。
 */
class AbstractDto extends ArrayDto
{
}
