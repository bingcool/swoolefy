<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Schema;

/**
 * 待解析文档来源描述。
 *
 * 只描述本地文件路径与类型信息，不绑定 Neuron Document / RAG 模型，
 * 便于同一解析结果复用到导出、摘要、结构化抽取等场景。
 */
final class DocumentSource
{
    /**
     * @param string               $path       已存在的本地绝对路径（建议 realpath）
     * @param string               $extension  小写扩展名，不含点（docx / png）
     * @param string|null          $mimeType   可选 MIME
     * @param array<string, mixed> $metadata   透传元数据（文件名、上传者等）
     */
    public function __construct(
        public readonly string $path,
        public readonly string $extension,
        public readonly ?string $mimeType = null,
        public readonly array $metadata = [],
    ) {
    }
}
