<?php

declare(strict_types=1);

namespace Swoolefy\Http;

/**
 * Swoole HTTP 上传文件封装，结构同 PHP $_FILES / Swoole\Http\Request->files。
 *
 * @see https://wiki.swoole.com/#/http_server?id=files
 */
final class UploadedFile
{
    private const ERROR_MESSAGES = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
    ];

    public function __construct(
        private string $clientOriginalName,
        private string $mimeType,
        private string $tempPath,
        private int $error,
        private int $size,
        private string $fieldName = '',
    ) {
    }

    /**
     * @param array<string, mixed> $files Swoole Request->files
     * @return array<string, self|list<self>>
     */
    public static function collectFromSwoole(array $files): array
    {
        $result = [];

        foreach ($files as $field => $file) {
            if (!is_array($file)) {
                continue;
            }

            if (self::isMultiUpload($file)) {
                $result[$field] = self::normalizeMultiUpload($file, (string) $field);
                continue;
            }

            if (isset($file['tmp_name'])) {
                $result[$field] = self::fromSwooleArray($file, (string) $field);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $file
     */
    public static function fromSwooleArray(array $file, string $fieldName = ''): self
    {
        return new self(
            (string) ($file['name'] ?? ''),
            (string) ($file['type'] ?? 'application/octet-stream'),
            (string) ($file['tmp_name'] ?? ''),
            (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
            (int) ($file['size'] ?? 0),
            $fieldName,
        );
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function getClientOriginalName(): string
    {
        return $this->clientOriginalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getTempPath(): string
    {
        return $this->tempPath;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getExtension(): string
    {
        return strtolower(pathinfo($this->clientOriginalName, PATHINFO_EXTENSION));
    }

    public function isImage(): bool
    {
        return str_starts_with(strtolower($this->mimeType), 'image/');
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && $this->tempPath !== ''
            && is_file($this->tempPath)
            && is_readable($this->tempPath);
    }

    public function getErrorMessage(): string
    {
        return self::ERROR_MESSAGES[$this->error] ?? 'Unknown upload error';
    }

    public function getContents(): string
    {
        $this->assertValid();

        $contents = file_get_contents($this->tempPath);
        if ($contents === false) {
            throw new UploadException('Cannot read uploaded file: ' . $this->tempPath);
        }

        return $contents;
    }

    /**
     * 将 Swoole 临时文件移动到目标目录。
     *
     * @return string 保存后的绝对路径
     */
    public function moveTo(string $directory, ?string $filename = null): string
    {
        $this->assertValid();

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new UploadException('Cannot create upload directory: ' . $directory);
        }

        $filename = $filename ?? $this->generateStoredFilename();
        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (is_file($target)) {
            throw new UploadException('Target file already exists: ' . $target);
        }

        if (!rename($this->tempPath, $target)) {
            if (!copy($this->tempPath, $target)) {
                throw new UploadException('Failed to move uploaded file to: ' . $target);
            }
            @unlink($this->tempPath);
        }

        return $target;
    }

    public function store(string $directory, ?string $filename = null): string
    {
        return $this->moveTo($directory, $filename);
    }

    public function validateMaxSize(int $maxBytes): void
    {
        if ($this->size > $maxBytes) {
            throw new UploadException(sprintf(
                'Uploaded file "%s" exceeds max size %d bytes',
                $this->clientOriginalName,
                $maxBytes,
            ));
        }
    }

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function validateMimeTypes(array $allowedMimeTypes): void
    {
        $mime = strtolower(trim(explode(';', $this->mimeType)[0]));
        $allowed = array_map(static fn (string $item): string => strtolower(trim($item)), $allowedMimeTypes);

        if (!in_array($mime, $allowed, true)) {
            throw new UploadException(sprintf(
                'Uploaded file "%s" mime type "%s" is not allowed',
                $this->clientOriginalName,
                $mime,
            ));
        }
    }

    /**
     * @param list<string> $allowedExtensions 不含点，如 ['jpg', 'png']
     */
    public function validateExtensions(array $allowedExtensions): void
    {
        $extension = $this->getExtension();
        $allowed = array_map('strtolower', $allowedExtensions);

        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw new UploadException(sprintf(
                'Uploaded file "%s" extension "%s" is not allowed',
                $this->clientOriginalName,
                $extension,
            ));
        }
    }

    /**
     * @param array<string, mixed> $file
     */
    private static function isMultiUpload(array $file): bool
    {
        return isset($file['name']) && is_array($file['name']);
    }

    /**
     * @param array<string, mixed> $file
     * @return list<self>
     */
    private static function normalizeMultiUpload(array $file, string $fieldName): array
    {
        $items = [];
        $names = $file['name'] ?? [];
        if (!is_array($names)) {
            return $items;
        }

        foreach (array_keys($names) as $index) {
            $items[] = self::fromSwooleArray([
                'name' => $file['name'][$index] ?? '',
                'type' => $file['type'][$index] ?? 'application/octet-stream',
                'tmp_name' => $file['tmp_name'][$index] ?? '',
                'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][$index] ?? 0,
            ], $fieldName);
        }

        return $items;
    }

    private function assertValid(): void
    {
        if ($this->isValid()) {
            return;
        }

        throw new UploadException($this->getErrorMessage());
    }

    private function generateStoredFilename(): string
    {
        $extension = $this->getExtension();
        $basename = pathinfo($this->clientOriginalName, PATHINFO_FILENAME);
        $basename = preg_replace('/[^\w\-.]+/u', '_', $basename) ?: 'upload';
        $suffix = $extension !== '' ? '.' . $extension : '';

        return $basename . '_' . bin2hex(random_bytes(8)) . $suffix;
    }
}
