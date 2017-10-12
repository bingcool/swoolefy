<?php

declare(strict_types=1);

namespace tests\Classification;

use Phpml\Classification\SVC;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\ModelManager;
use PHPUnit\Framework\TestCase;

class SVCTest extends TestCase
{
    public function testPredictSingleSampleWithLinearKernel()
    {
        $samples = [[1, 3], [1, 4], [2, 4], [3, 1], [4, 1], [4, 2]];
        $labels = ['a', 'a', 'a', 'b', 'b', 'b'];

        $classifier = new SVC(Kernel::LINEAR, $cost = 1000);
        $classifier->train($samples, $labels);

        $this->assertEquals('b', $classifier->predict([3, 2]));
        $this->assertEquals('b', $classifier->predict([5, 1]));
        $this->assertEquals('b', $classifier->predict([4, 3]));
        $this->assertEquals('b', $classifier->predict([4, -5]));

        $this->assertEquals('a', $classifier->predict([2, 3]));
        $this->assertEquals('a', $classifier->predict([1, 2]));
        $this->assertEquals('a', $classifier->predict([1, 5]));
        $this->assertEquals('a', $classifier->predict([3, 10]));
    }

    public function testPredictArrayOfSamplesWithLinearKernel()
    {
        $trainSamples = [[1, 3], [1, 4], [2, 4], [3, 1], [4, 1], [4, 2]];
        $trainLabels = ['a', 'a', 'a', 'b', 'b', 'b'];

        $testSamples = [[3, 2], [5, 1], [4, 3], [4, -5], [2, 3], [1, 2], [1, 5], [3, 10]];
        $testLabels = ['b', 'b', 'b', 'b', 'a', 'a', 'a', 'a'];

        $classifier = new SVC(Kernel::LINEAR, $cost = 1000);
        $classifier->train($trainSamples, $trainLabels);
        $predictions = $classifier->predict($testSamples);

        $this->assertEquals($testLabels, $predictions);
    }

    public function testSaveAndRestore()
    {
        $trainSamples = [[1, 3], [1, 4], [2, 4], [3, 1], [4, 1], [4, 2]];
        $trainLabels = ['a', 'a', 'a', 'b', 'b', 'b'];

        $testSamples = [[3, 2], [5, 1], [4, 3]];
        $testLabels = ['b', 'b', 'b'];

        $classifier = new SVC(Kernel::LINEAR, $cost = 1000);
        $classifier->train($trainSamples, $trainLabels);
        $predicted = $classifier->predict($testSamples);

        $filename = 'svc-test-'.rand(100, 999).'-'.uniqid();
        $filepath = tempnam(sys_get_temp_dir(), $filename);
        $modelManager = new ModelManager();
        $modelManager->saveToFile($classifier, $filepath);

        $restoredClassifier = $modelManager->restoreFromFile($filepath);
        $this->assertEquals($classifier, $restoredClassifier);
        $this->assertEquals($predicted, $restoredClassifier->predict($testSamples));
    }
}
