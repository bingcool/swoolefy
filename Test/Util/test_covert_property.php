<?php
/**
 * 测试嵌套 ArrayList 转换
 */

require_once __DIR__ . '/../../autoloader.php';

use Swoolefy\Util\CovertProperty;
use Test\Module\Order\Response\LogResponse;

// 模拟 API 返回的数据
$data = [
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
                            'subCateName' => 'sub category 11'
                        ]
                    ]
                ],
                [
                    'cateId' => 2,
                    'cateName' => 'category 2',
                    'subCategories' => [
                        [
                            'subCateId' => 21,
                            'subCateName' => 'sub category 21'
                        ],
                        [
                            'subCateId' => 22,
                            'subCateName' => 'sub category 22'
                        ]
                    ]
                ]
            ]
        ],
        [
            'name' => 'test2',
            'value' => 'value string 2',
            'categories' => [
                [
                    'cateId' => 3,
                    'cateName' => 'category 3',
                    'subCategories' => []
                ]
            ]
        ]
    ]
];

echo "========== 开始测试嵌套 ArrayList 转换 ==========\n\n";

try {
    /** @var LogResponse $response */
    $response = CovertProperty::toCovertDeepProperty($data, LogResponse::class);
    
    echo "转换成功！\n";
    echo "Code: " . $response->getCode() . "\n";
    echo "Message: " . $response->getMessage() . "\n";
    echo "Data 类型: " . gettype($response->getData()) . "\n";
    echo "Data 数量: " . count($response->getData()) . "\n\n";
    
    // 检查第一层：LogContentRespDto
    foreach ($response->getData() as $index => $logContent) {
        echo "--- LogContent #{$index} ---\n";
        echo "  Name: " . $logContent->getName() . "\n";
        echo "  Value: " . $logContent->getValue() . "\n";
        echo "  Categories 类型: " . gettype($logContent->getCategories()) . "\n";
        echo "  Categories 数量: " . count($logContent->getCategories()) . "\n";
        
        // 检查第二层：CategoryRespDto
        foreach ($logContent->getCategories() as $cateIndex => $category) {
            echo "    -- Category #{$cateIndex} --\n";
            echo "      CateId: " . $category->getCateId() . "\n";
            echo "      CateName: " . $category->getCateName() . "\n";
            echo "      SubCategories 类型: " . gettype($category->getSubCategories()) . "\n";
            echo "      SubCategories 数量: " . count($category->getSubCategories()) . "\n";
            
            // 检查第三层：SubCategoryRespDto
            foreach ($category->getSubCategories() as $subCateIndex => $subCategory) {
                echo "        * SubCategory #{$subCateIndex} *\n";
                echo "          SubCateId: " . $subCategory->getSubCateId() . "\n";
                echo "          SubCateName: " . $subCategory->getSubCateName() . "\n";
            }
        }
        echo "\n";
    }
    
    echo "========== 测试完成 ==========\n";
    
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    echo "\n堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
}
