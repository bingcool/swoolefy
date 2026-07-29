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

use InvalidArgumentException;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;

/**
 * 将 {@see SkillDefinition} 转为 Neuron Tool，或生成 instructions 注入块。
 *
 * Tool 名：skill_{name}，其中 name 的非 [a-zA-Z0-9_] 字符替换为下划线。
 *
 * skillsMode：
 * - tool（默认）：挂载 skill_*，正文仅作 tool result；短列表引导按需调用；
 * - inline：正文注入 instructions，不挂载 skill_*；
 * - both：正文注入 + 仍挂载 skill_*（可能重复占 token）。
 */
final class SkillToolFactory
{
    public const MODE_TOOL = 'tool';

    public const MODE_INLINE = 'inline';

    public const MODE_BOTH = 'both';

    /** @var list<string> */
    public const MODES = [self::MODE_TOOL, self::MODE_INLINE, self::MODE_BOTH];

    /**
     * @throws InvalidArgumentException 非法 mode
     */
    public static function normalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));
        if (!in_array($normalized, self::MODES, true)) {
            throw new InvalidArgumentException(
                'skillsMode must be one of: tool, inline, both; got: ' . $mode,
            );
        }

        return $normalized;
    }

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
     * @param string                $mode   tool|inline|both（非法值抛 InvalidArgumentException）
     */
    public static function availableSkillsPrompt(array $skills, string $mode = self::MODE_TOOL): string
    {
        if ($skills === []) {
            return '';
        }

        $mode = self::normalizeMode($mode);

        $lines = ['<AVAILABLE-SKILLS>'];
        foreach ($skills as $skill) {
            $desc = $skill->description !== '' ? $skill->description : '(no description)';
            if ($mode === self::MODE_INLINE) {
                $lines[] = sprintf('- %s: %s', $skill->name, $desc);
            } else {
                $tool = self::toolName($skill->name);
                $lines[] = sprintf('- %s (tool: %s): %s', $skill->name, $tool, $desc);
            }
        }

        $lines[] = match ($mode) {
            self::MODE_INLINE => 'These skills are already inlined in this prompt as <SKILL> blocks. Follow them directly; do not call skill_* tools.',
            self::MODE_BOTH => 'Skill bodies may also be inlined below as <SKILL> blocks. skill_* tools remain available, but calling them may duplicate tokens.',
            default => 'Call the matching skill_* tool when you need that procedural knowledge.',
        };
        $lines[] = '</AVAILABLE-SKILLS>';

        return implode("\n", $lines);
    }

    /**
     * 将已加载 Skill 的 markdown 正文注入 instructions（包一层 &lt;SKILL&gt;）。
     *
     * @param list<SkillDefinition> $skills
     */
    public static function inlineSkillsPrompt(array $skills): string
    {
        if ($skills === []) {
            return '';
        }

        $blocks = [];
        foreach ($skills as $skill) {
            $blocks[] = sprintf(
                "<SKILL name=\"%s\">\n%s\n</SKILL>",
                $skill->name,
                $skill->content,
            );
        }

        return implode("\n\n", $blocks);
    }
}
