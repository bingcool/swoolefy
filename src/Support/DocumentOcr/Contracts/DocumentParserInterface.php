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
     * 不支持时抛 UnsupportedDocumentException；执行失败抛 ParserException。
     *
     * @throws \Swoolefy\Support\DocumentOcr\Exceptions\ParserException
     * @throws \Swoolefy\Support\DocumentOcr\Exceptions\UnsupportedDocumentException
     */
    public function parse(DocumentSource $source, ?ParseOptions $options = null): ParseResult;
}
