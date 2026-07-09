<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Contracts;

use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;
use Swoolefy\Support\DocumentOcr\Schema\ParseOptions;
use Swoolefy\Support\DocumentOcr\Schema\ParseResult;

/**
 * 文档解析器契约（Pandoc / DeepSeek OCR / AutoParser 均实现）。
 */
interface DocumentParserInterface
{
    /** Driver 稳定名称，对应 ParseOptions.parser / 配置段键。 */
    public function name(): string;

    /** 当前 source 是否可由本 Driver 处理。 */
    public function supports(DocumentSource $source): bool;

    /**
     * 将文档解析为 Markdown。
     *
     * @throws \Swoolefy\Support\DocumentOcr\Exceptions\DocumentParseException
     * @throws \Swoolefy\Support\DocumentOcr\Exceptions\UnsupportedDocumentTypeException
     */
    public function parse(DocumentSource $source, ?ParseOptions $options = null): ParseResult;
}
