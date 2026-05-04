<?php

declare(strict_types=1);

namespace Swoolefy\Script\Sdk;

/**
 * Copies Test\* request/response/DTO sources into Swoolefy\GenerateSdk\Test\* with lightweight bases.
 */
final class SdkDtoWriter
{
    private const TEST_PREFIX = 'Test\\';

    private const SDK_NAMESPACE = 'Swoolefy\\GenerateSdk\\Test';

    public function __construct(
        private string $projectRoot,
        private string $outputTestRoot,
    ) {
    }

    public function writeClass(string $className): void
    {
        if (!str_starts_with($className, self::TEST_PREFIX)) {
            return;
        }

        if (!class_exists($className)) {
            fwrite(STDERR, "[gen:sdk] DTO class not loadable, skip: {$className}\n");
            return;
        }

        $rc = new \ReflectionClass($className);
        $srcFile = $rc->getFileName();
        if ($srcFile === false || !is_readable($srcFile)) {
            fwrite(STDERR, "[gen:sdk] No source file for {$className}\n");
            return;
        }

        $raw = file_get_contents($srcFile);
        if ($raw === false) {
            return;
        }

        $transformed = $this->transformSource($raw);
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, substr($className, strlen('Test\\'))) . '.php';
        $dest = $this->outputTestRoot . DIRECTORY_SEPARATOR . $relativePath;
        $destDir = dirname($dest);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        file_put_contents($dest, $transformed);
    }

    private function transformSource(string $php): string
    {
        $php = $this->stripPhp8Attributes($php);

        $replacements = [
            'namespace Test\\' => 'namespace ' . self::SDK_NAMESPACE . '\\',
            'use Test\\' => 'use ' . self::SDK_NAMESPACE . '\\',
            'use Swoolefy\\Http\\BaseRequest;' => 'use ' . self::SDK_NAMESPACE . '\\Support\\SdkBaseRequest;',
            'extends BaseRequest' => 'extends SdkBaseRequest',
            'use Swoolefy\\Http\\BaseResponse;' => 'use ' . self::SDK_NAMESPACE . '\\Support\\SdkBaseResponse;',
            'extends BaseResponse' => 'extends SdkBaseResponse',
            'use Swoolefy\\Core\\Dto\\AbstractDto;' => 'use ' . self::SDK_NAMESPACE . '\\Support\\SdkAbstractDto;',
            'extends AbstractDto' => 'extends SdkAbstractDto',
        ];

        $out = str_replace(array_keys($replacements), array_values($replacements), $php);

        $out = preg_replace('/^\s*use\s+Swoolefy\\\\Annotation\\\\Validation\\\\ValidationRule;\s*$/m', '', $out) ?? $out;

        if (!str_contains($out, 'declare(strict_types=1);')) {
            $out = preg_replace('/^<\?php\s*\n/', "<?php\n\ndeclare(strict_types=1);\n\n", $out, 1) ?? $out;
        }

        return $out;
    }

    /**
     * Remove PHP 8 attributes (e.g. ValidationRule) from source; bracket depth handles multi-line.
     */
    private function stripPhp8Attributes(string $php): string
    {
        $lines = explode("\n", $php);
        $out = [];
        $depth = 0;
        foreach ($lines as $line) {
            if ($depth > 0) {
                $depth += substr_count($line, '[') - substr_count($line, ']');
                if ($depth <= 0) {
                    $depth = 0;
                }
                continue;
            }

            if (preg_match('/^\s*#\[/', $line)) {
                $depth = substr_count($line, '[') - substr_count($line, ']');
                if ($depth <= 0) {
                    continue;
                }
                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }
}
