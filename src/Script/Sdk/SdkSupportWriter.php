<?php

declare(strict_types=1);

namespace Swoolefy\Script\Sdk;

/**
 * 将 Sdk/Stubs 模板写入生成 SDK 的 Support 目录。
 */
final class SdkSupportWriter
{
    private SdkStubLoader $stubs;

    public function __construct(
        private string $supportDir,
        private string $supportNamespace,
        private string $nacosServiceName = 'my-service',
        ?SdkStubLoader $stubLoader = null,
    ) {
        $this->stubs = $stubLoader ?? new SdkStubLoader();
    }

    public function writeAll(): void
    {
        if (!is_dir($this->supportDir)) {
            mkdir($this->supportDir, 0755, true);
        }

        $files = [
            'SdkArrayDto.php' => 'SdkArrayDto',
            'SdkAbstractDto.php' => 'SdkAbstractDto',
            'SdkBaseRequest.php' => 'SdkBaseRequest',
            'SdkBasePageRequest.php' => 'SdkBasePageRequest',
            'SdkBaseResponse.php' => 'SdkBaseResponse',
            'SdkBasePageResultResponse.php' => 'SdkBasePageResultResponse',
            'SdkClientException.php' => 'SdkClientException',
            'BaseClientApi.php' => 'BaseClientApi',
            'SdkNacosServiceDiscovery.php' => 'SdkNacosServiceDiscovery',
            'ApiProperty.php' => 'ApiProperty',
            'ArrayList.php' => 'ArrayList',
            'SdkCovertProperty.php' => 'SdkCovertProperty',
            'SdkArrayInterface.php' => 'SdkArrayInterface',
            'SdkArrayInteger.php' => 'SdkArrayInteger',
            'SdkArrayString.php' => 'SdkArrayString',
            'StringToInt.php' => 'StringToInt',
            'IntToString.php' => 'IntToString',
        ];

        foreach ($files as $outputFile => $stubName) {
            file_put_contents(
                $this->supportDir . '/' . $outputFile,
                $this->render($stubName),
            );
        }
    }

    /**
     * @param array<string, string> $extraReplacements
     */
    private function render(string $stubName, array $extraReplacements = []): string
    {
        $replacements = array_merge([
            '__SDK_SUPPORT_NAMESPACE__' => $this->supportNamespace,
        ], $extraReplacements);

        if ($stubName === 'BaseClientApi') {
            $replacements['__SDK_NACOS_SERVICE_NAME__'] = addcslashes($this->nacosServiceName, "'\\");
        }

        return $this->stubs->load($stubName, $replacements);
    }
}
