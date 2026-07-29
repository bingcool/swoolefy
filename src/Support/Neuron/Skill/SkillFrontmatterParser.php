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

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use InvalidArgumentException;

/**
 * Cursor 风格 SKILL.md frontmatter 解析（--- yaml --- + markdown body）。
 */
final class SkillFrontmatterParser
{
    /**
     * @return array{frontmatter: array<string, mixed>, content: string}
     */
    public static function parse(string $raw): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $trimmed = ltrim($normalized);

        if (!str_starts_with($trimmed, "---\n") && $trimmed !== '---') {
            return [
                'frontmatter' => [],
                'content' => trim($normalized),
            ];
        }

        // 去掉文件开头空白后的起始 ---
        $afterOpen = substr($trimmed, 4);
        $closePos = strpos($afterOpen, "\n---");
        if ($closePos === false) {
            throw new InvalidArgumentException('SKILL.md frontmatter is missing closing --- delimiter');
        }

        $yaml = substr($afterOpen, 0, $closePos);
        $body = substr($afterOpen, $closePos + 4); // skip \n---
        if (str_starts_with($body, "\n")) {
            $body = substr($body, 1);
        }

        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new InvalidArgumentException('SKILL.md frontmatter YAML is invalid: ' . $e->getMessage(), 0, $e);
        }

        if ($parsed === null) {
            $parsed = [];
        }
        if (!is_array($parsed)) {
            throw new InvalidArgumentException('SKILL.md frontmatter must be a YAML mapping');
        }

        /** @var array<string, mixed> $parsed */
        return [
            'frontmatter' => $parsed,
            'content' => trim($body),
        ];
    }
}
