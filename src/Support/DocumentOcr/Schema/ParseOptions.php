<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Schema;

/**
 * 单次解析选项。
 *
 * Phase 1 只保留强制指定 parser；后续可扩展语言、OCR 参数等。
 */
final class ParseOptions
{
    /**
     * @param string|null $parser 强制驱动名：pandoc / deepseek_ocr；null 时由 AutoParser 按扩展名选择
     */
    public function __construct(
        public readonly ?string $parser = null,
    ) {
    }
}
