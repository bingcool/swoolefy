# DocumentOcr — 文档解析 / OCR → Markdown

将 DOCX / HTML / MD / TXT（Pandoc）与 PNG / JPG（DeepSeek OCR）转为统一的 `ParseResult.markdown`，**不绑定** Neuron `Document`。入库时再经 `ChunkingAdapter` 接入 RAG。

- 设计文档：[docs/DocumentOcr.md](../../../docs/DocumentOcr.md)
- 配置：`Test/Config/document_ocr.php`
- 组件：`document_ocr` → `DocumentOcrFactory`

Phase 1 **不支持 PDF**。

---

## 快速上手

```php
/** @var \Swoolefy\Support\DocumentOcr\DocumentOcrFactory $ocr */
$ocr = Application::getApp()->get('document_ocr');

$result = $ocr->parseFile('/data/manual.docx');
// $result->markdown, $result->parserName, $result->selectionReason

$chunks = (new \Swoolefy\Support\DocumentOcr\Chunking\ChunkingAdapter())
    ->splitParseResult($result, sourceName: 'manual.docx');
// $pipeline->ingest('product_kb', $chunks);
```

---

## 目录结构

```
DocumentOcr/
├── DocumentOcrFactory.php
├── Contracts/
├── Loaders/LocalFileLoader.php
├── Parsers/{AutoParser,PandocDriver,DeepSeekOcrDriver}.php
├── Markdown/MarkdownNormalizer.php
├── Chunking/ChunkingAdapter.php
├── Schema/
├── WorkDirectory.php
├── Exceptions/
└── Tests/DocumentOcrTest.php
```

---

## AutoParser 选择

| 输入 | Driver | selectionReason |
|------|--------|-----------------|
| `.docx` / `.html` / `.md` / `.txt` | PandocDriver | `structured_format:docx` |
| `.png` / `.jpg` / `.jpeg` | DeepSeekOcrDriver | `image_extension:png` |
| `.pdf` | 抛异常 | Phase 1 不支持 |

`ParseOptions(parser: 'pandoc')` 可强制指定驱动。

---

## 测试

```bash
composer test:document-ocr
# 或
php src/Support/DocumentOcr/Tests/DocumentOcrTest.php
```

Pandoc / OCR 均通过注入 mock，不依赖本机 pandoc 或 OCR 服务。
