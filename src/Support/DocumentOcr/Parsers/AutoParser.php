<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Parsers;

use Swoolefy\Support\DocumentOcr\Contracts\DocumentParserInterface;
use Swoolefy\Support\DocumentOcr\Contracts\ParserSelectorInterface;
use Swoolefy\Support\DocumentOcr\Exceptions\UnsupportedDocumentException;
use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;
use Swoolefy\Support\DocumentOcr\Schema\ParseOptions;
use Swoolefy\Support\DocumentOcr\Schema\ParseResult;

/**
 * 按扩展名自动选择 Driver，并委托解析。
 *
 * 选择规则：
 * - docx/doc/html/htm/md/txt → PandocDriver（structured_format:{ext}）
 * - png/jpg/jpeg → DeepSeekOcrDriver（image_extension:{ext}）
 * - pdf → DeepSeekOcrDriver（pdf_direct_deepseek_ocr，endpoint=/api/ocr/pdf）
 *
 * 无命中或强制 parser 不支持时抛 UnsupportedDocumentException，不返回 null。
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

        return $result->with(['selectionReason' => $reason]);
    }

    /**
     * {@inheritdoc}
     *
     * @return array{0: DocumentParserInterface, 1: string}
     */
    public function select(DocumentSource $source, ?ParseOptions $options = null): array
    {
        $forced = $options?->parser;
        if (is_string($forced) && $forced !== '' && $forced !== self::NAME) {
            foreach ($this->parsers as $parser) {
                if ($parser->name() === $forced) {
                    if (!$parser->supports($source)) {
                        throw new UnsupportedDocumentException(
                            sprintf('Forced parser [%s] does not support extension [%s]', $forced, $source->extension),
                        );
                    }

                    return [$parser, 'forced_parser:' . $forced];
                }
            }

            throw new UnsupportedDocumentException('Unknown parser: ' . $forced);
        }

        foreach ($this->parsers as $parser) {
            if (!$parser->supports($source)) {
                continue;
            }

            $ext = strtolower($source->extension);
            if ($parser->name() === PandocDriver::NAME) {
                $reason = 'structured_format:' . $ext;
            } elseif ($ext === 'pdf') {
                $reason = 'pdf_direct_deepseek_ocr';
            } else {
                $reason = 'image_extension:' . $ext;
            }

            return [$parser, $reason];
        }

        throw new UnsupportedDocumentException(
            'No DocumentOcr driver available for extension: ' . ($source->extension !== '' ? $source->extension : '(none)'),
        );
    }
}
