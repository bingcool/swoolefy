<?php

declare(strict_types=1);

namespace Swoolefy\Script\InterfaceApi;

use Swoolefy\Script\Sdk\SdkStubLoader;

/**
 * 将 InterfaceApi Support 模板写入目标目录下的 InterfaceApi/Support。
 */
final class InterfaceApiSupportWriter
{
    public const SUPPORT_NAMESPACE = 'InterfaceApi\Support';

    private SdkStubLoader $sdkStubs;

    private SdkStubLoader $localStubs;

    public function __construct(
        private string $supportDir,
        ?SdkStubLoader $sdkStubLoader = null,
        ?SdkStubLoader $localStubLoader = null,
    ) {
        $this->sdkStubs = $sdkStubLoader ?? new SdkStubLoader();
        $this->localStubs = $localStubLoader ?? new SdkStubLoader(__DIR__ . '/Stubs');
    }

    /**
     * @return list<string> 已写入文件的绝对路径
     */
    public function writeAll(): array
    {
        if (!is_dir($this->supportDir)) {
            mkdir($this->supportDir, 0755, true);
        }

        $written = [];

        foreach ($this->localStubOutputs() as $outputFile => $stubName) {
            $path = $this->supportDir . '/' . $outputFile;
            file_put_contents($path, $this->renderLocalStub($stubName));
            $written[] = $path;
        }

        foreach ($this->sdkStubOutputs() as $outputFile => $sdkStubName) {
            $path = $this->supportDir . '/' . $outputFile;
            $content = $this->renderSdkStub($sdkStubName);
            if ($outputFile === 'ArrayDto.php') {
                $content = $this->alignArrayDtoWithInterfaceApiSpec($content);
            }
            file_put_contents($path, $content);
            $written[] = $path;
        }

        return $written;
    }

    /**
     * @return array<string, string> output filename => stub basename
     */
    private function localStubOutputs(): array
    {
        return [
            'Route.php' => 'Route',
            'RouteGroup.php' => 'RouteGroup',
            'AbstractValidationRule.php' => 'AbstractValidationRule',
            'ValidationRule.php' => 'ValidationRule',
            'ApiController.php' => 'ApiController',
            'ApiOperation.php' => 'ApiOperation',
            'StreamResponse.php' => 'StreamResponse',
            'ChunkedResponse.php' => 'ChunkedResponse',
            'DownloadResponse.php' => 'DownloadResponse',
            'BaseRequest.php' => 'BaseRequest',
            'BaseResponse.php' => 'BaseResponse',
            'AbstractListDataDto.php' => 'AbstractListDataDto',
            'AbstractPageDataDto.php' => 'AbstractPageDataDto',
        ];
    }

    /**
     * @return array<string, string> output filename => Sdk stub basename
     */
    private function sdkStubOutputs(): array
    {
        return [
            'ApiProperty.php' => 'ApiProperty',
            'ArrayList.php' => 'ArrayList',
            'StringToInt.php' => 'StringToInt',
            'IntToString.php' => 'IntToString',
            'InteractsWithDtoArrayAccess.php' => 'SdkInteractsWithDtoArrayAccess',
            'ArrayDto.php' => 'SdkArrayDto',
            'AbstractDto.php' => 'SdkAbstractDto',
            'BasePageRequest.php' => 'SdkBasePageRequest',
            'ClientException.php' => 'SdkClientException',
            'BaseClientApi.php' => 'BaseClientApi',
            'NacosServiceDiscovery.php' => 'SdkNacosServiceDiscovery',
            'CovertProperty.php' => 'SdkCovertProperty',
            'ArrayInterface.php' => 'SdkArrayInterface',
            'ArrayInteger.php' => 'SdkArrayInteger',
            'ArrayString.php' => 'SdkArrayString',
        ];
    }

    private function renderLocalStub(string $stubName): string
    {
        return $this->localStubs->load($stubName, [
            '__INTERFACE_API_SUPPORT_NAMESPACE__' => self::SUPPORT_NAMESPACE,
        ]);
    }

    private function renderSdkStub(string $sdkStubName): string
    {
        $content = $this->sdkStubs->load($sdkStubName, [
            '__SDK_SUPPORT_NAMESPACE__' => self::SUPPORT_NAMESPACE,
        ]);

        return str_replace(array_keys($this->sdkClassRenames()), array_values($this->sdkClassRenames()), $content);
    }

    /**
     * @return array<string, string>
     */
    private function alignArrayDtoWithInterfaceApiSpec(string $content): string
    {
        $content = str_replace(
            'class ArrayDto extends \\stdClass implements ArrayAccess, JsonSerializable, IteratorAggregate',
            'class ArrayDto extends \\stdClass implements ArrayInterface, ArrayAccess, JsonSerializable, IteratorAggregate',
            $content,
        );

        if (!str_contains($content, 'function builder():')) {
            $content = str_replace(
                "    use InteractsWithDtoArrayAccess;\n",
                "    use InteractsWithDtoArrayAccess;\n\n    public static function builder(): static\n    {\n        return new static();\n    }\n",
                $content,
            );
        }

        return $content;
    }

    /**
     * @return array<string, string>
     */
    private function sdkClassRenames(): array
    {
        return [
            'SdkInteractsWithDtoArrayAccess' => 'InteractsWithDtoArrayAccess',
            'SdkArrayDto' => 'ArrayDto',
            'SdkAbstractDto' => 'AbstractDto',
            'SdkBaseRequest' => 'BaseRequest',
            'SdkBasePageRequest' => 'BasePageRequest',
            'SdkBaseResponse' => 'BaseResponse',
            'SdkClientException' => 'ClientException',
            'SdkNacosServiceDiscovery' => 'NacosServiceDiscovery',
            'SdkCovertProperty' => 'CovertProperty',
            'SdkArrayInterface' => 'ArrayInterface',
            'SdkArrayInteger' => 'ArrayInteger',
            'SdkArrayString' => 'ArrayString',
        ];
    }
}
