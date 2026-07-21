<?php

declare(strict_types=1);

/**
 * FileStorageSystem 配置模版（create 时复制为 Config/file_storage_system.php）
 *
 * 用法：
 * - DI：Application::getApp()->get('file_storage')->disk() 或 disk('aws_s3')
 * - 直接：new FileStorageManager(include APP_PATH . '/Config/file_storage_system.php')
 *
 * 说明：
 * - default_provider：disk() 未传名时使用的 provider 键名
 * - file_system_providers：可配置多个盘；键名任意，driver 决定实现
 * - 云驱动密钥优先读环境变量；生产勿把明文密钥提交进仓库
 * - credentials.provider = static | sts；sts 需另配 callable fetcher（见 docs）
 * - Manager 只缓存 Disk 实例，不缓存 STS Token（Token 缓存在 StsCredentialProvider 内）
 *
 * @see \Swoolefy\Library\FileStorageSystem\FileStorageManager
 * @see \Swoolefy\Library\FileStorageSystem\FileStorageFactory
 * @see docs/fileStorageSystem.md
 * @see Config/component/file_storage.php
 */

return [
    // 默认盘：对应下方 file_system_providers 的键名（非 driver 名）
    'default_provider' => env('FILE_STORAGE_DEFAULT', 'local'),

    'file_system_providers' => [

        // ---------------------------------------------------------------------
        // local：本机目录（Flysystem Local）
        // - getObjectUrl 依赖 public_base_url；未配置则抛 ObjectUrlNotSupportedException
        // - 不支持预签名 URL（signObjectUrl / getTemporaryUrl）
        // - multipart：临时文件 + stream_copy + rename，非云式分片
        // ---------------------------------------------------------------------
        'local' => [
            'driver' => 'local',
            // 本地根目录；对象 path 相对该 root（经 PathNormalizer，禁止 .. 逃逸）
            'root' => env(
                'FILE_STORAGE_LOCAL_ROOT',
                (defined('APP_PATH') ? APP_PATH : sys_get_temp_dir()) . '/Storage/Upload'
            ),
            // 对外访问前缀，如 https://static.example.com/uploads；空字符串表示不启用公开 URL
            'public_base_url' => env('FILE_STORAGE_LOCAL_PUBLIC_URL', ''),
            // 预留：与 Flysystem throw 语义对齐；当前适配器失败统一抛 FileStorage 异常
            'throw' => true,
        ],

        // ---------------------------------------------------------------------
        // fake：进程内内存盘（单测 / 本地联调，无 IO、无云）
        // - putObjectMultipart 直接整包写入；禁止 createMultipartUpload / uploadPart
        // ---------------------------------------------------------------------
        'fake' => [
            'driver' => 'fake',
        ],

        // ---------------------------------------------------------------------
        // aws_s3：Amazon S3（需 aws/aws-sdk-php）
        // - key/secret 也可写在 credentials 下；顶层 key/secret 为便捷别名
        // - endpoint + use_path_style：MinIO / 兼容 S3 网关常用
        // - multipart_part_size：单片大小，S3 要求通常 ≥ 5MiB（最后一片除外）
        // ---------------------------------------------------------------------
        'aws_s3' => [
            'driver' => 'aws_s3',
            'key' => env('AWS_ACCESS_KEY_ID', ''),
            'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
            // 可选：临时会话 Token（STS）；长期密钥可省略
            // 'token' => env('AWS_SESSION_TOKEN', null),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET', ''),
            // 自定义 Endpoint（MinIO 等）；官方 S3 留 null
            'endpoint' => env('AWS_ENDPOINT', null),
            // true = path-style（http://endpoint/bucket/key）；false = virtual-hosted
            'use_path_style' => (bool) env('AWS_USE_PATH_STYLE', false),
            // 默认 ACL 语义提示（putObject 时 visibility=public 会映射为 public-read）
            'visibility' => 'private',
            // 分片上传每片字节数（工厂会钳制下限 5MiB）
            'multipart_part_size' => 8 * 1024 * 1024,
            // 预留：超过该大小自动走 multipart 的阈值（当前由业务显式调 putObjectMultipart）
            'multipart_threshold' => 16 * 1024 * 1024,
            'credentials' => [
                // static：使用上方 key/secret；sts：需配置 'fetcher' => callable
                'provider' => 'static',
                // STS 示例：
                // 'provider' => 'sts',
                // 'fetcher' => static function (): array {
                //     return [
                //         'key' => '...',
                //         'secret' => '...',
                //         'token' => '...',
                //         'expires_at' => time() + 3600, // Unix 秒；Provider 提前 60s 刷新
                //     ];
                // },
            ],
            'throw' => true,
        ],

        // ---------------------------------------------------------------------
        // aliyun_oss：阿里云 OSS（需 alibabacloud/oss-v2）
        // - region 如 cn-hangzhou；endpoint 可覆盖默认 oss-{region}.aliyuncs.com
        // - multipart_part_size 下限约 100KiB（适配器内钳制）
        // ---------------------------------------------------------------------
        'aliyun_oss' => [
            'driver' => 'aliyun_oss',
            'key' => env('OSS_ACCESS_KEY_ID', ''),
            'secret' => env('OSS_ACCESS_KEY_SECRET', ''),
            'region' => env('OSS_REGION', 'cn-hangzhou'),
            'bucket' => env('OSS_BUCKET', ''),
            // 可选自定义 Endpoint；内网/加速域名等
            'endpoint' => env('OSS_ENDPOINT', null),
            'multipart_part_size' => 8 * 1024 * 1024,
            'credentials' => [
                'provider' => 'static',
            ],
            'throw' => true,
        ],

        // ---------------------------------------------------------------------
        // tengxun_cos：腾讯云 COS（需 qcloud/cos-sdk-v5）
        // - bucket 常为 {name}-{appid} 形式；也可单独配 app_id
        // - multipart_part_size 下限约 1MiB（适配器内钳制）
        // ---------------------------------------------------------------------
        'tengxun_cos' => [
            'driver' => 'tengxun_cos',
            'key' => env('COS_SECRET_ID', ''),
            'secret' => env('COS_SECRET_KEY', ''),
            'region' => env('COS_REGION', 'ap-guangzhou'),
            // 例：mybucket-1250000000
            'bucket' => env('COS_BUCKET', ''),
            // 腾讯云 APPID；部分账号/SDK 场景需要
            'app_id' => env('COS_APP_ID', null),
            'endpoint' => env('COS_ENDPOINT', null),
            'multipart_part_size' => 8 * 1024 * 1024,
            'credentials' => [
                'provider' => 'static',
            ],
            'throw' => true,
        ],
    ],
];
