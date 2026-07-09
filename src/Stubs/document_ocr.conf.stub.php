<?php

declare(strict_types=1);

/**
 * DocumentOcr 配置模板（create 应用时可复制到 Config/document_ocr.php）。
 *
 * @see docs/DocumentOcr.md
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
        'timeout' => (int) env('DEEPSEEK_OCR_TIMEOUT', 120),
        'clean_temp' => true,
        'output_mmd' => true,
        'allowed_extensions' => ['png', 'jpg', 'jpeg'],
        'max_file_size' => (int) env('DEEPSEEK_OCR_MAX_FILE_SIZE', 10_485_760),
        'work_dir' => env('DOCUMENT_OCR_DEEPSEEK_WORK_DIR', '/tmp/swoolefy_document_ocr/deepseek'),
        'retry' => [
            'times' => (int) env('DEEPSEEK_OCR_RETRY_TIMES', 1),
        ],
    ],
];
