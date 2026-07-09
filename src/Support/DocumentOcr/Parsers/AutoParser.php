<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Parsers;

use Swoolefy\Support\DocumentOcr\Contracts\DocumentParserInterface;
use Swoolefy\Support\DocumentOcr\Contracts\ParserSelectorInterface;
use Swoolefy\Support\DocumentOcr\Exceptions\UnsupportedDocumentTypeException;
use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;
use Swoolefy\Support\DocumentOcr\Schema\ParseOptions;
use Swoolefy\Support\DocumentOcr\Schema\ParseResult;

/**
 * 按扩展名自动选择 Driver，并委托解析。
 *
 * 选择规则（Phase 1）：
 * - docx/doc/html/htm/md/txt → PandocDriver
 * - png/jpg/jpeg → DeepSeekOcrDriver
 * - pdf 及其它 → UnsupportedDocumentTypeException
 *
 * ParseOptions.parser 可强制指定驱动名。
 */
final class AutoParser implements DocumentParserInterface, ParserSelectorInterface
{
    public const NAME = 'auto';

    /**
     * @param list<DocumentParserInterface> $parsers 已启用的 Driver 列表
     */
    public function __construct(
        private readonly array $parsers,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(DocumentSource $source): bool
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($source)) {
                return true;
            }
        }

        return false;
    }

    public function parse(DocumentSource $source, ?ParseOptions $options = null): ParseResult
    {
        [$parser, $reason] = $this->select($source, $options);
        $result = $parser->parse($source, $options);

        // 以 AutoParser 选择原因为准，便于日志统一排查
        return $result->with(['selectionReason' => $reason]);
    }

    /**
     * {@inheritdoc}
     */
    public function select(DocumentSource $source, ?ParseOptions $options = null): array
    {
        $forced = $options?->parser;
        if (is_string($forced) && $forced !== '' && $forced !== self::NAME) {
            foreach ($this->parsers as $parser) {
                if ($parser->name() === $forced) {
                    if (!$parser->supports($source)) {
                        throw new UnsupportedDocumentTypeException(
                            sprintf('Forced parser [%s] does not support extension [%s]', $forced, $source->extension),
                        );
                    }

                    return [$parser, 'forced_parser:' . $forced];
                }
            }

            throw new UnsupportedDocumentTypeException('Unknown parser: ' . $forced);
        }

        // PDF Phase 1 明确不支持，给出更清晰错误
        if (strtolower($source->extension) === 'pdf') {
            throw new UnsupportedDocumentTypeException(
                'PDF is not supported in DocumentOcr Phase 1; use Phase 2 PDF split + OCR later',
            );
        }

        foreach ($this->parsers as $parser) {
            if ($parser->supports($source)) {
                $reason = $parser->name() === PandocDriver::NAME
                    ? 'structured_format:' . $source->extension
                    : 'image_extension:' . $source->extension;

                return [$parser, $reason];
            }
        }

        throw new UnsupportedDocumentTypeException(
            'No DocumentOcr driver available for extension: ' . ($source->extension !== '' ? $source->extension : '(none)'),
        );
    }
}
