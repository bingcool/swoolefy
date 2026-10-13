<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use Swoolefy\Core\CommandRunner;
use Swoolefy\Exception\SystemException;

final class CommandRunnerShellArgsTest extends TestCase
{
    public function testInjectionPayloadsArriveAsLiteralArguments(): void
    {
        $marker = sys_get_temp_dir() . '/swoolefy_cmd_inject_' . bin2hex(random_bytes(4));
        $payloads = [
            "a; touch {$marker}",
            "\$(touch {$marker})",
            "`touch {$marker}`",
            "x' ; touch {$marker}; echo '",
            "a b | touch {$marker}",
        ];

        $args = CommandRunner::buildShellArgs(array_merge($payloads, ['name' => "v; touch {$marker}"]));
        exec("printf '%s\\n' {$args}", $output, $code);

        $this->assertSame(0, $code);
        $this->assertSame(array_merge($payloads, ["--name=v; touch {$marker}"]), $output);
        $this->assertFileDoesNotExist($marker);
    }

    public function testScalarValuesAreStringified(): void
    {
        $this->assertSame("--daemon='1' --off='0' --n='3' 'pos'", CommandRunner::buildShellArgs([
            'daemon' => true,
            'off' => false,
            'n' => 3,
            'pos',
        ]));
        $this->assertSame('', CommandRunner::buildShellArgs([]));
    }

    public function testInvalidOptionNameIsRejected(): void
    {
        $this->expectException(SystemException::class);
        CommandRunner::buildShellArgs(['a;rm -rf /' => 'x']);
    }

    public function testNonScalarValueIsRejected(): void
    {
        $this->expectException(SystemException::class);
        CommandRunner::buildShellArgs(['list' => ['a', 'b']]);
    }
}
