<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Schema;

/**
 * 统一解析输出。
 *
 * 上层只依赖 markdown + metadata，不绑定具体 Driver。
 * Factory::parseFile() 会在返回前统一走 MarkdownNormalizer。
 */
final class ParseResult
{
    /**
     * @param string               $markdown         解析得到的 Markdown 正文
     * @param string               $parserName       实际使用的 Driver 名
     * @param string               $selectionReason  选择原因，便于日志调试
     * @param int                  $durationMs       解析耗时（毫秒）
     * @param string|null          $sourceHash       源文件内容 hash（sha256），便于后续缓存
     * @param array<string, mixed> $metadata         扩展元数据
     */
    public function __construct(
        public readonly string $markdown,
        public readonly string $parserName,
        public readonly string $selectionReason = '',
        public readonly int $durationMs = 0,
        public readonly ?string $sourceHash = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * 返回带覆盖字段的新实例（不可变 DTO 的轻量 with）。
     *
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            markdown: array_key_exists('markdown', $overrides) ? (string) $overrides['markdown'] : $this->markdown,
            parserName: array_key_exists('parserName', $overrides) ? (string) $overrides['parserName'] : $this->parserName,
            selectionReason: array_key_exists('selectionReason', $overrides) ? (string) $overrides['selectionReason'] : $this->selectionReason,
            durationMs: array_key_exists('durationMs', $overrides) ? (int) $overrides['durationMs'] : $this->durationMs,
            sourceHash: array_key_exists('sourceHash', $overrides) ? ($overrides['sourceHash'] !== null ? (string) $overrides['sourceHash'] : null) : $this->sourceHash,
            metadata: array_key_exists('metadata', $overrides) && is_array($overrides['metadata']) ? $overrides['metadata'] : $this->metadata,
        );
    }
}
