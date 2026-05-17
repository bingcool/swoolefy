<?php
namespace Test\Scripts\TestScript;

use Swoolefy\Exception\SystemException;
use Swoolefy\Script\MainCliScript;
use Swoolefy\Util\CovertProperty;
use Test\Module\Order\Response\CategoryRespDto;
use Test\Module\Order\Response\LogContentRespDto;
use Test\Module\Order\Response\LogResponse;
use Test\Module\Order\Response\SubCategoryRespDto;

/**
 * use this command:
 *           php script.php start Test --c=bingcool:test --a=testEcho
 */
class BingcoolTest extends MainCliScript
{
    const command = 'bingcool:test';
    public function handle()
    {
        // 获取测试脚本类需要执行的方法
        $a = $this->getOption('a');
        if (!method_exists($this, $a)) {
            throw new SystemException('method '.$a.' not exists in class='.get_class($this));
        }
        $this->$a();
    }

    /**
     * testEcho测试输出方法
     * php script.php start Test --c=bingcool:test --a=testEcho
     */
    public function testEcho()
    {
        echo "test order\n";
    }

    /**
     * 测试嵌套 ArrayList 转换
     * php script.php start Test --c=bingcool:test --a=testCovertProperty
     */
    public function testCovertProperty()
    {
        /** @var LogResponse $response */
        $response = CovertProperty::toCovertDeepProperty($this->nestedArrayListData(), LogResponse::class);

        $data = $response->getData();
        $this->assertSame(2, count($data), 'LogResponse data count should be 2');

        $firstLogContent = $data[0] ?? null;
        $this->assertInstanceOf(LogContentRespDto::class, $firstLogContent, 'First data item should be LogContentRespDto');
        $this->assertSame('test1', $firstLogContent->getName(), 'First log content name mismatch');
        $this->assertSame('value string', $firstLogContent->getValue(), 'First log content value mismatch');

        $firstCategories = $firstLogContent->getCategories();
        $this->assertSame(2, count($firstCategories), 'First log content categories count should be 2');

        $firstCategory = $firstCategories[0] ?? null;
        $this->assertInstanceOf(CategoryRespDto::class, $firstCategory, 'First category should be CategoryRespDto');
        $this->assertSame(1, $firstCategory->getCateId(), 'First category cateId mismatch');
        $this->assertSame('category 1', $firstCategory->getCateName(), 'First category cateName mismatch');

        $firstSubCategories = $firstCategory->getSubCategories();
        $this->assertSame(1, count($firstSubCategories), 'First category subCategories count should be 1');
        $this->assertInstanceOf(SubCategoryRespDto::class, $firstSubCategories[0] ?? null, 'First sub category should be SubCategoryRespDto');
        $this->assertSame(11, $firstSubCategories[0]->getSubCateId(), 'First sub category subCateId mismatch');
        $this->assertSame('sub category 11', $firstSubCategories[0]->getSubCateName(), 'First sub category subCateName mismatch');

        $secondCategory = $firstCategories[1] ?? null;
        $this->assertInstanceOf(CategoryRespDto::class, $secondCategory, 'Second category should be CategoryRespDto');
        $this->assertSame(2, count($secondCategory->getSubCategories()), 'Second category subCategories count should be 2');
        $this->assertSame(22, $secondCategory->getSubCategories()[1]->getSubCateId(), 'Second category second subCateId mismatch');

        $secondLogContent = $data[1] ?? null;
        $this->assertInstanceOf(LogContentRespDto::class, $secondLogContent, 'Second data item should be LogContentRespDto');
        $this->assertSame('test2', $secondLogContent->getName(), 'Second log content name mismatch');
        $this->assertSame(1, count($secondLogContent->getCategories()), 'Second log content categories count should be 1');
        $this->assertSame(0, count($secondLogContent->getCategories()[0]->getSubCategories()), 'Empty subCategories should stay empty');

        echo "testCovertProperty passed\n";
    }

    private function nestedArrayListData(): array
    {
        return [
            'code' => 0,
            'message' => 'success',
            'data' => [
                [
                    'name' => 'test1',
                    'value' => 'value string',
                    'categories' => [
                        [
                            'cateId' => 1,
                            'cateName' => 'category 1',
                            'subCategories' => [
                                [
                                    'subCateId' => 11,
                                    'subCateName' => 'sub category 11',
                                ],
                            ],
                        ],
                        [
                            'cateId' => 2,
                            'cateName' => 'category 2',
                            'subCategories' => [
                                [
                                    'subCateId' => 21,
                                    'subCateName' => 'sub category 21',
                                ],
                                [
                                    'subCateId' => 22,
                                    'subCateName' => 'sub category 22',
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'test2',
                    'value' => 'value string 2',
                    'categories' => [
                        [
                            'cateId' => 3,
                            'cateName' => 'category 3',
                            'subCategories' => [],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message . ', expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
        }
    }

    private function assertInstanceOf(string $expectedClass, mixed $actual, string $message): void
    {
        if (!$actual instanceof $expectedClass) {
            $actualType = is_object($actual) ? get_class($actual) : gettype($actual);
            throw new \RuntimeException($message . ', expected=' . $expectedClass . ', actual=' . $actualType);
        }
    }
}