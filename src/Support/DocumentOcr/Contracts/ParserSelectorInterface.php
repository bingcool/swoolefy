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

namespace Swoolefy\Support\DocumentOcr\Contracts;

use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;
use Swoolefy\Support\DocumentOcr\Schema\ParseOptions;

/**
 * 按扩展名 / 强制选项选择具体 DocumentParser。
 */
interface ParserSelectorInterface
{
    /**
     * @return array{0: DocumentParserInterface, 1: string} [parser, selectionReason]
     *
     * @throws \Swoolefy\Support\DocumentOcr\Exceptions\UnsupportedDocumentException
     */
    public function select(DocumentSource $source, ?ParseOptions $options = null): array;
}
