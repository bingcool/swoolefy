<?php

declare(strict_types=1);

namespace tests\SupportVectorMachine;

use Phpml\SupportVectorMachine\DataTransformer;
use PHPUnit\Framework\TestCase;

class DataTransformerTest extends TestCase
{
    public function testTransformDatasetToTrainingSet()
    {
        $samples = [[1, 1], [2, 1], [3, 2], [4, 5]];
        $labels = ['a', 'a', 'b', 'b'];

        $trainingSet =
            '0 1:1 2:1 '.PHP_EOL.
            '0 1:2 2:1 '.PHP_EOL.
            '1 1:3 2:2 '.PHP_EOL.
            '1 1:4 2:5 '.PHP_EOL
        ;

        $this->assertEquals($trainingSet, DataTransformer::trainingSet($samples, $labels));
    }

    public function testTransformSamplesToTestSet()
    {
        $samples = [[1, 1], [2, 1], [3, 2], [4, 5]];

        $testSet =
            '0 1:1 2:1 '.PHP_EOL.
            '0 1:2 2:1 '.PHP_EOL.
            '0 1:3 2:2 '.PHP_EOL.
            '0 1:4 2:5 '.PHP_EOL
        ;

        $this->assertEquals($testSet, DataTransformer::testSet($samples));
    }
}
