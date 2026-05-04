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
        $transformed = $this->appendCollectionAdders($className, $transformed);
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
        $php = $this->filterPhp8AttributesKeepApiProperty($php);

        $replacements = [
            'namespace Test\\' => 'namespace ' . self::SDK_NAMESPACE . '\\',
            'use Test\\' => 'use ' . self::SDK_NAMESPACE . '\\',
            'use Swoolefy\\Http\\BaseRequest;' => 'use ' . self::SDK_NAMESPACE . '\\Support\\SdkBaseRequest;',
            'extends BaseRequest' => 'extends SdkBaseRequest',
            'use Swoolefy\\Http\\BaseResponse;' => 'use ' . self::SDK_NAMESPACE . '\\Support\\SdkBaseResponse;',
            'extends BaseResponse' => 'extends SdkBaseResponse',
            'use Swoolefy\\Core\\Dto\\AbstractDto;' => 'use ' . self::SDK_NAMESPACE . '\\Support\\SdkAbstractDto;',
            'extends AbstractDto' => 'extends SdkAbstractDto',
            'extends \Swoolefy\Core\Dto\AbstractDto' => 'extends SdkAbstractDto',
            'use Swoolefy\\Annotation\\ApiProperty;' => 'use ' . self::SDK_NAMESPACE . '\\Support\\ApiProperty;',
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
            && !str_contains($out, 'use ' . self::SDK_NAMESPACE . '\\Support\\SdkAbstractDto;')) {
            $out = $this->mergeUseStatements($out, [self::SDK_NAMESPACE . '\\Support\\SdkAbstractDto']);
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
        $specs = SdkDtoReflection::listCollectionAdderSpecs($testClassFqcn);
        if ($specs === []) {
            return $php;
        }

        $useFqcn = [];
        $methods = [];
        foreach ($specs as $spec) {
            $sdkItemFqcn = self::SDK_NAMESPACE . '\\' . substr($spec['itemFqcn'], strlen('Test\\'));
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
