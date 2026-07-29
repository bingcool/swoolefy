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

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Skill;

use RuntimeException;

/**
 * 按名称显式加载 Skill 时未找到对应 SKILL.md。
 */
final class SkillNotFoundException extends RuntimeException
{
    /**
     * @param list<string> $roots 已扫描的 skill 根目录
     */
    public function __construct(string $name, array $roots = [])
    {
        $rootsHint = $roots === []
            ? 'no skill roots configured'
            : 'searched roots: ' . implode(', ', $roots);

        parent::__construct(sprintf('Skill "%s" not found (%s)', $name, $rootsHint));
    }
}
