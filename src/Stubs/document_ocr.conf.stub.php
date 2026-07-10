<?php

declare(strict_types=1);

/**
 * DocumentOcr 配置模板（create 应用时复制到 Config/document_ocr.php）。
 *
 * AutoParser 按扩展名选择 Driver：
 * - docx/doc/html/htm/md/txt → PandocDriver
 * - png/jpg/jpeg → DeepSeekOcrDriver（endpoint）
 * - pdf → DeepSeekOcrDriver（pdf_endpoint）
 *
 * 组件注册见 Config/component/document_parser.php（DI 名 document_ocr）。
 *
 * @see docs/DocumentOcr.md
 */
return [
    // Pandoc：DOCX / DOC / HTML / MD / TXT → Markdown（本机 pandoc 可执行文件）
    'pandoc' => [
        // 是否启用；false 时 AutoParser 不会注册 PandocDriver
        'enabled' => filter_var(env('DOCUMENT_OCR_PANDOC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // pandoc 可执行文件路径，可用绝对路径
        'bin' => env('PANDOC_BIN', 'pandoc'),
        // CommandRunner 实例名，用于并发限流隔离
        'runner_name' => 'document-pandoc',
        // 同 runner 下最大并发数
        'concurrent' => 2,
        // 支持的输入扩展名（小写）
        'input_formats' => ['docx', 'doc', 'html', 'htm', 'md', 'txt'],
        // pandoc -t 输出格式，推荐 gfm
        'output_format' => 'gfm',
        // 解析临时目录（每次任务子目录，结束后清理）
        'work_dir' => env('DOCUMENT_OCR_PANDOC_WORK_DIR', '/tmp/swoolefy_document_ocr/pandoc'),
    ],

    // DeepSeek-OCR：图片走 /api/ocr，PDF 走 /api/ocr/pdf（同一服务，不同 endpoint）
    'deepseek_ocr' => [
        // 是否启用；false 时不注册 DeepSeekOcrDriver
        'enabled' => filter_var(env('DOCUMENT_OCR_DEEPSEEK_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // OCR HTTP 服务根地址
        'base_uri' => env('DEEPSEEK_OCR_BASE_URI', 'http://127.0.0.1:7860'),
        // 图片 OCR 路径（png/jpg/jpeg）
        'endpoint' => env('DEEPSEEK_OCR_ENDPOINT', '/api/ocr'),
        // PDF OCR 路径（服务端多页处理）
        'pdf_endpoint' => env('DEEPSEEK_OCR_PDF_ENDPOINT', '/api/ocr/pdf'),
        // 请求总超时（秒）；必须大于 connect_timeout，否则构造期抛 ParserException
        'time_out' => (int) env('DEEPSEEK_OCR_TIME_OUT', env('DEEPSEEK_OCR_TIMEOUT', 120)),
        // 建连超时（秒）
        'connect_timeout' => (int) env('DEEPSEEK_OCR_CONNECT_TIMEOUT', 3),
        // 最大重试次数（不含首次；1 表示最多请求 2 次）
        'max_retry_num' => (int) env('DEEPSEEK_OCR_MAX_RETRY_NUM', 1),
        // 重试间隔（毫秒）；驱动内上限 2000
        'retry_sleep_ms' => (int) env('DEEPSEEK_OCR_RETRY_SLEEP_MS', 1000),
        // 传给 OCR 服务的 clean_temp 表单字段
        'clean_temp' => true,
        // 传给 OCR 服务的 output_mmd 表单字段（优先返回 markdown）
        'output_mmd' => true,
        // 本 Driver 接受的扩展名；须含 pdf 才会走 pdf_endpoint
        'allowed_extensions' => ['png', 'jpg', 'jpeg', 'pdf'],
        // 图片最大字节数
        'max_file_size' => (int) env('DEEPSEEK_OCR_MAX_FILE_SIZE', 20 * 1024 * 1024),
        // PDF 最大字节数（通常大于图片）
        'pdf_max_file_size' => (int) env('DEEPSEEK_OCR_PDF_MAX_FILE_SIZE', 100 * 1024 * 1024),
        // OCR 返回本地路径时，仅允许读取此目录下文件
        'work_dir' => env('DEEPSEEK_OCR_WORK_DIR', env('DOCUMENT_OCR_DEEPSEEK_WORK_DIR', '/tmp/swoolefy_document_ocr/deepseek')),
    ],
];
