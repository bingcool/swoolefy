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

/**
 * 本地 SKILL.md 解析结果。
 *
 * name / description 来自 YAML frontmatter；content 为去掉 frontmatter 后的 markdown 正文。
 */
final class SkillDefinition
{
    /**
     * @param array<string, mixed> $metadata frontmatter 其余字段
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $content,
        public readonly string $path,
        public readonly array $metadata = [],
    ) {
    }
}
