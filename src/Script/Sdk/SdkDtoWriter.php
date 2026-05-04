<?php

declare(strict_types=1);

namespace Swoolefy\Script\Sdk;

/**
 * Copies {APP_NAME}\* request/response/DTO sources into GenerateSdk\{ProjectName}\{AppName}\* with lightweight bases.
 */
final class SdkDtoWriter
{
    public function __construct(
        private string $projectRoot,
        private string $outputTestRoot,
        private string $sdkNamespacePrefix,
        private string $appNamespacePrefix,
    ) {
    }

    public function writeClass(string $className): void
    {
        if (!str_starts_with($className, $this->appNamespacePrefix)) {
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
        $transformed = $this->appendCollectionAdders($className, $transformed);
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, substr($className, strlen($this->appNamespacePrefix))) . '.php';
        $dest = $this->outputTestRoot . DIRECTORY_SEPARATOR . $relativePath;
        $this->ensureParentDir($dest);

        file_put_contents($dest, $transformed);
    }

    /**
     * Copies {APP_NAME}/Common/Const and Common/Enum into the SDK tree when present, applying the same
     * namespace / use rewrites as DTOs so enums and constants stay usable from generated clients.
     */
    public function copyCommonConstAndEnumTrees(): void
    {
        $appSrcRoot = $this->projectRoot . DIRECTORY_SEPARATOR . APP_NAME;
        if (!is_dir($appSrcRoot)) {
            return;
        }

        foreach (['Common' . DIRECTORY_SEPARATOR . 'Const', 'Common' . DIRECTORY_SEPARATOR . 'Enum'] as $sub) {
            $srcRoot = $appSrcRoot . DIRECTORY_SEPARATOR . $sub;
            if (!is_dir($srcRoot)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $fullPath = $file->getRealPath();
                if ($fullPath === false) {
                    continue;
                }

                $prefix = $appSrcRoot . DIRECTORY_SEPARATOR;
                if (!str_starts_with($fullPath, $prefix)) {
                    continue;
                }

                $relativeUnderApp = substr($fullPath, strlen($prefix));
                $dest = $this->outputTestRoot . DIRECTORY_SEPARATOR . $relativeUnderApp;
                $this->writeTransformedPhpFromPath($fullPath, $dest);
            }
        }
    }

    private function writeTransformedPhpFromPath(string $srcPath, string $destPath): void
    {
        $raw = file_get_contents($srcPath);
        if ($raw === false) {
            fwrite(STDERR, "[gen:sdk] Cannot read Common tree file: {$srcPath}\n");

            return;
        }

        $transformed = $this->transformSource($raw);
        $this->ensureParentDir($destPath);
        file_put_contents($destPath, $transformed);
    }

    private function ensureParentDir(string $filePath): void
    {
        $destDir = dirname($filePath);
        if (is_dir($destDir)) {
            return;
        }
        mkdir($destDir, 0755, true);
    }

    private function transformSource(string $php): string
    {
        $php = $this->filterPhp8AttributesKeepApiProperty($php);

        $ns = $this->sdkNamespacePrefix;
        $ap = $this->appNamespacePrefix;
        $replacements = [
            'namespace ' . $ap => 'namespace ' . $ns . '\\',
            'use ' . $ap => 'use ' . $ns . '\\',
            'use Swoolefy\\Http\\BaseRequest;' => 'use ' . $ns . '\\Support\\SdkBaseRequest;',
            'extends BaseRequest' => 'extends SdkBaseRequest',
            'use Swoolefy\\Http\\BaseResponse;' => 'use ' . $ns . '\\Support\\SdkBaseResponse;',
            'extends BaseResponse' => 'extends SdkBaseResponse',
            'use Swoolefy\\Core\\Dto\\AbstractDto;' => 'use ' . $ns . '\\Support\\SdkAbstractDto;',
            'extends AbstractDto' => 'extends SdkAbstractDto',
            'extends \Swoolefy\Core\Dto\AbstractDto' => 'extends SdkAbstractDto',
            'use Swoolefy\\Annotation\\ApiProperty;' => 'use ' . $ns . '\\Support\\ApiProperty;',
        ];

        $out = str_replace(array_keys($replacements), array_values($replacements), $php);

        $out = preg_replace('/^\s*use\s+Swoolefy\\\\Annotation\\\\Validation\\\\ValidationRule;\s*$/m', '', $out) ?? $out;
        $out = preg_replace('/^\s*use\s+Swoolefy\\\\Annotation\\\\ResponseProperty;\s*$/m', '', $out) ?? $out;
        $out = preg_replace('/^\s*use\s+Swoolefy\\\\Annotation\\\\IntToString;\s*$/m', '', $out) ?? $out;
        $out = preg_replace('/^\s*use\s+OpenApi\\\\Attributes\\\\[^;]+;\s*$/m', '', $out) ?? $out;

        if (!str_contains($out, 'declare(strict_types=1);')) {
            $out = preg_replace('/^<\?php\s*\n/', "<?php\n\ndeclare(strict_types=1);\n\n", $out, 1) ?? $out;
        }

        if (str_contains($out, 'extends SdkAbstractDto')
            && !str_contains($out, 'use ' . $this->sdkNamespacePrefix . '\\Support\\SdkAbstractDto;')) {
            $out = $this->mergeUseStatements($out, [$this->sdkNamespacePrefix . '\\Support\\SdkAbstractDto']);
        }

        return $out;
    }

    /**
     * Strip framework attributes (ValidationRule, etc.) but keep #[ApiProperty(...)] for generated SDK docs.
     */
    private function filterPhp8AttributesKeepApiProperty(string $php): string
    {
        $lines = explode("\n", $php);
        $out = [];
        $depth = 0;
        $keepBlock = false;
        foreach ($lines as $line) {
            if ($depth > 0) {
                $depth += substr_count($line, '[') - substr_count($line, ']');
                if ($keepBlock) {
                    $out[] = $line;
                }
                if ($depth <= 0) {
                    $depth = 0;
                    $keepBlock = false;
                }
                continue;
            }

            if (preg_match('/^\s*#\[/', $line)) {
                $keepBlock = $this->attributeLineOpensApiProperty($line);
                $depth = substr_count($line, '[') - substr_count($line, ']');
                if ($keepBlock) {
                    $out[] = $line;
                }
                if ($depth <= 0) {
                    $depth = 0;
                    $keepBlock = false;
                }
                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private function attributeLineOpensApiProperty(string $line): bool
    {
        return (bool) preg_match('/^\s*#\[.*\bApiProperty\b\s*[\(\]]/', $line);
    }

    /**
     * Appends add{ItemDto}(...) helpers for array-of-DTO properties (from ValidationRule/ResponseProperty itemClass or @var array<X>).
     */
    private function appendCollectionAdders(string $testClassFqcn, string $php): string
    {
        $specs = SdkDtoReflection::listCollectionAdderSpecs($testClassFqcn, $this->appNamespacePrefix);
        if ($specs === []) {
            return $php;
        }

        $useFqcn = [];
        $methods = [];
        foreach ($specs as $spec) {
            $sdkItemFqcn = $this->sdkNamespacePrefix . '\\' . substr($spec['itemFqcn'], strlen($this->appNamespacePrefix));
            $useFqcn[$sdkItemFqcn] = true;
            $short = substr($sdkItemFqcn, strrpos($sdkItemFqcn, '\\') + 1);
            $prop = $spec['property'];
            $mn = $spec['methodName'];
            $methods[] = <<<PHP
    public function {$mn}({$short} \$dto): void
    {
        \$this->{$prop}[] = \$dto;
    }
PHP;
        }

        $php = $this->mergeUseStatements($php, array_keys($useFqcn));

        return $this->injectBeforeClassClosingBrace($php, "\n" . implode("\n\n", $methods));
    }

    /**
     * @param list<string> $fqcnList
     */
    private function mergeUseStatements(string $php, array $fqcnList): string
    {
        sort($fqcnList);
        $toAdd = [];
        foreach ($fqcnList as $fq) {
            $line = 'use ' . $fq . ';';
            if (str_contains($php, $line)) {
                continue;
            }
            $toAdd[] = $line;
        }
        if ($toAdd === []) {
            return $php;
        }
        if (preg_match('/^(namespace\s[^;]+;\s*\n)/m', $php, $m)) {
            $insert = $m[0] . "\n" . implode("\n", $toAdd) . "\n";

            return preg_replace('/^namespace\s[^;]+;\s*\n/m', $insert, $php, 1) ?? $php;
        }

        return $php;
    }

    private function injectBeforeClassClosingBrace(string $php, string $append): string
    {
        $append = trim($append);
        if ($append === '') {
            return $php;
        }
        $pos = strrpos($php, "\n}");
        if ($pos === false) {
            return rtrim($php) . "\n" . $append . "\n}\n";
        }

        return substr($php, 0, $pos) . "\n" . $append . substr($php, $pos);
    }
}
