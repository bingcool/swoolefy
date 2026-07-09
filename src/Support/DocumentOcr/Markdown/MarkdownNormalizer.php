<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Markdown;

/**
 * Markdown 轻量规范化。
 *
 * 由 DocumentOcrFactory::parseFile() 统一调用，Driver 内部不要重复 normalize。
 * Phase 1 只做空白收敛，避免破坏表格 / 代码块语义。
 */
final class MarkdownNormalizer
{
    public function normalize(string $markdown): string
    {
        // 统一换行
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        // 去掉首尾空白
        $text = trim($text);
        // 连续空行压缩为最多两个换行（保留段落分隔）
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return $text;
    }
}
