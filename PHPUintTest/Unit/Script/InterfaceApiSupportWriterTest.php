<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Script;

use PHPUnit\Framework\TestCase;
use Swoolefy\Script\InterfaceApi\InterfaceApiSupportWriter;

final class InterfaceApiSupportWriterTest extends TestCase
{
    public function testWriteAllCreatesSupportFiles(): void
    {
        $base = sys_get_temp_dir() . '/interface-api-support-' . uniqid('', true);
        $supportDir = $base . '/InterfaceApi/Support';

        try {
            $written = (new InterfaceApiSupportWriter($supportDir))->writeAll();

            $this->assertGreaterThanOrEqual(25, count($written));
            $this->assertFileExists($supportDir . '/Route.php');
            $this->assertFileExists($supportDir . '/ArrayDto.php');
            $this->assertFileExists($supportDir . '/BaseClientApi.php');

            $routeContent = file_get_contents($supportDir . '/Route.php');
            $this->assertIsString($routeContent);
            $this->assertStringContainsString('namespace InterfaceApi\Support;', $routeContent);

            $arrayDto = file_get_contents($supportDir . '/ArrayDto.php');
            $this->assertIsString($arrayDto);
            $this->assertStringContainsString('implements ArrayInterface, ArrayAccess', $arrayDto);
            $this->assertStringContainsString('function builder():', $arrayDto);

            $baseResponse = file_get_contents($supportDir . '/BaseResponse.php');
            $this->assertIsString($baseResponse);
            $this->assertStringContainsString('return $this->msg;', $baseResponse);
        } finally {
            $this->removeDir($base);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
