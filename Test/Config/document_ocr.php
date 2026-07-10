<?php

declare(strict_types=1);

/**
 * DocumentOcr 配置：Pandoc 结构化文档 + DeepSeek OCR（图片 / PDF）。
 *
 * @see docs/DocumentOcr.md
 * @see src/Support/DocumentOcr/
 */
return [
    'pandoc' => [
        'enabled' => filter_var(env('DOCUMENT_OCR_PANDOC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'bin' => env('PANDOC_BIN', 'pandoc'),
        'runner_name' => 'document-pandoc',
        'concurrent' => 2,
        'input_formats' => ['docx', 'doc', 'html', 'htm', 'md', 'txt'],
        'output_format' => 'gfm',
        'work_dir' => env('DOCUMENT_OCR_PANDOC_WORK_DIR', '/tmp/swoolefy_document_ocr/pandoc'),
    ],
    'deepseek_ocr' => [
        'enabled' => filter_var(env('DOCUMENT_OCR_DEEPSEEK_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'base_uri' => env('DEEPSEEK_OCR_BASE_URI', 'http://127.0.0.1:7860'),
        'endpoint' => env('DEEPSEEK_OCR_ENDPOINT', '/api/ocr'),
        'pdf_endpoint' => env('DEEPSEEK_OCR_PDF_ENDPOINT', '/api/ocr/pdf'),
        'time_out' => (int) env('DEEPSEEK_OCR_TIME_OUT', env('DEEPSEEK_OCR_TIMEOUT', 120)),
        'connect_timeout' => (int) env('DEEPSEEK_OCR_CONNECT_TIMEOUT', 3),
        'max_retry_num' => (int) env('DEEPSEEK_OCR_MAX_RETRY_NUM', 1),
        'retry_sleep_ms' => (int) env('DEEPSEEK_OCR_RETRY_SLEEP_MS', 1000),
        'clean_temp' => true,
        'output_mmd' => true,
        'allowed_extensions' => ['png', 'jpg', 'jpeg', 'pdf'],
        'max_file_size' => (int) env('DEEPSEEK_OCR_MAX_FILE_SIZE', 20 * 1024 * 1024),
        'pdf_max_file_size' => (int) env('DEEPSEEK_OCR_PDF_MAX_FILE_SIZE', 100 * 1024 * 1024),
        'work_dir' => env('DEEPSEEK_OCR_WORK_DIR', env('DOCUMENT_OCR_DEEPSEEK_WORK_DIR', '/tmp/swoolefy_document_ocr/deepseek')),
    ],
];
