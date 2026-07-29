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

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;

/**
 * 将 {@see SkillDefinition} 转为 Neuron Tool（按需加载正文，不把全文塞进 system prompt）。
 *
 * Tool 名：skill_{name}，其中 name 的非 [a-zA-Z0-9_] 字符替换为下划线。
 */
final class SkillToolFactory
{
    public static function toolName(string $skillName): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_]+/', '_', trim($skillName)) ?? '';
        $normalized = trim($normalized, '_');
        if ($normalized === '') {
            $normalized = 'unnamed';
        }

        return 'skill_' . strtolower($normalized);
    }

    public static function make(SkillDefinition $skill): ToolInterface
    {
        $description = $skill->description !== ''
            ? $skill->description
            : sprintf('Load procedural instructions for the "%s" skill.', $skill->name);

        $content = $skill->content;

        return Tool::make(
            self::toolName($skill->name),
            $description,
        )->setCallable(static function () use ($content): string {
            return $content;
        });
    }

    /**
     * @param list<SkillDefinition> $skills
     *
     * @return list<ToolInterface>
     */
    public static function makeMany(array $skills): array
    {
        $tools = [];
        foreach ($skills as $skill) {
            $tools[] = self::make($skill);
        }

        return $tools;
    }

    /**
     * 供 system prompt 挂载的短列表（名称 + description，不含正文）。
     *
     * @param list<SkillDefinition> $skills
     */
    public static function availableSkillsPrompt(array $skills): string
    {
        if ($skills === []) {
            return '';
        }

        $lines = ['<AVAILABLE-SKILLS>'];
        foreach ($skills as $skill) {
            $tool = self::toolName($skill->name);
            $desc = $skill->description !== '' ? $skill->description : '(no description)';
            $lines[] = sprintf('- %s (tool: %s): %s', $skill->name, $tool, $desc);
        }
        $lines[] = 'Call the matching skill_* tool when you need that procedural knowledge.';
        $lines[] = '</AVAILABLE-SKILLS>';

        return implode("\n", $lines);
    }
}
