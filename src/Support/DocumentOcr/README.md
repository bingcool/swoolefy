# DocumentOcr — 文档解析 / OCR → Markdown

将 DOCX / HTML / MD / TXT（Pandoc）、PNG / JPG（DeepSeek OCR）与 PDF（DeepSeek OCR `/api/ocr/pdf`）转为统一的 `ParseResult.markdown`，**不绑定** Neuron `Document`。入库时再经 `ChunkingAdapter` 接入 RAG。

- 设计文档：[docs/DocumentOcr.md](../../../docs/DocumentOcr.md)
- 配置：`Test/Config/document_ocr.php`
- 组件：`Test/Config/component/document_parser.php` → DI 名 `document_ocr` → `DocumentOcrFactory`

---

## 快速上手

```php
/** @var \Swoolefy\Support\DocumentOcr\DocumentOcrFactory $ocr */
$ocr = Application::getApp()->get('document_ocr');

$result = $ocr->parseFile('/data/manual.docx');
// $result->markdown, $result->parserName, $result->selectionReason, $result->metadata

$chunks = (new \Swoolefy\Support\DocumentOcr\Chunking\ChunkingAdapter())
    ->splitParseResult($result, sourceName: 'manual.docx');
// $pipeline->ingest('product_kb', $chunks);
```

异常捕获：

```php
use Swoolefy\Support\DocumentOcr\Exceptions\DocumentException;
use Swoolefy\Support\DocumentOcr\Exceptions\ParserException;
use Swoolefy\Support\DocumentOcr\Exceptions\UnsupportedDocumentException;

try {
    $result = $ocr->parseFile($path);
} catch (UnsupportedDocumentException $e) {
    // 无可用 parser / 类型不支持
} catch (ParserException $e) {
    // parser 已选中但执行失败
} catch (DocumentException $e) {
    // 其它 DocumentOcr 异常（如文件不存在）
}
```

---

## AutoParser 选择

| 输入 | Driver | selectionReason |
|------|--------|-----------------|
| `.docx` / `.html` / `.md` / `.txt` | PandocDriver | `structured_format:docx` |
| `.png` / `.jpg` / `.jpeg` | DeepSeekOcrDriver | `image_extension:png` |
| `.pdf` | DeepSeekOcrDriver（`/api/ocr/pdf`） | `pdf_direct_deepseek_ocr` |
| 其它 | — | 抛 `UnsupportedDocumentException` |

`ParseOptions(parser: 'pandoc')` 可强制指定驱动。

---

## 配置要点

`deepseek_ocr` 关键键：

- `endpoint` / `pdf_endpoint`：图片与 PDF 分流
- `time_out` 必须 **大于** `connect_timeout`（构造期校验）
- `max_retry_num` / `retry_sleep_ms`（休眠上限 2000ms）
- `max_file_size` / `pdf_max_file_size`

可注入外部 Guzzle Client：`DocumentOcrFactory::fromConfig($config, deepSeekClient: $client)`。

---

## 测试

```bash
composer test:document-ocr
# 或
php src/Support/DocumentOcr/Tests/DocumentOcrTest.php
```

Pandoc / OCR 均通过注入 mock，不依赖本机 pandoc 或 OCR 服务。
