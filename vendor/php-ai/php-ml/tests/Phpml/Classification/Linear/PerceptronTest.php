<?php

declare(strict_types=1);

namespace tests\Classification\Linear;

use Phpml\Classification\Linear\Perceptron;
use Phpml\ModelManager;
use PHPUnit\Framework\TestCase;

class PerceptronTest extends TestCase
{
    public function testPredictSingleSample()
    {
        // AND problem
        $samples = [[0, 0], [1, 0], [0, 1], [1, 1], [0.6, 0.6]];
        $targets = [0, 0, 0, 1, 1];
        $classifier = new Perceptron(0.001, 5000);
        $classifier->train($samples, $targets);
        $this->assertEquals(0, $classifier->predict([0.1, 0.2]));
        $this->assertEquals(0, $classifier->predict([0, 1]));
        $this->assertEquals(1, $classifier->predict([1.1, 0.8]));

        // OR problem
        $samples = [[0.1, 0.1], [0.4, 0.], [0., 0.3], [1, 0], [0, 1], [1, 1]];
        $targets = [0, 0, 0, 1, 1, 1];
        $classifier = new Perceptron(0.001, 5000, false);
        $classifier->train($samples, $targets);
        $this->assertEquals(0, $classifier->predict([0., 0.]));
        $this->assertEquals(1, $classifier->predict([0.1, 0.99]));
        $this->assertEquals(1, $classifier->predict([1.1, 0.8]));

        // By use of One-v-Rest, Perceptron can perform multi-class classification
        // The samples should be separable by lines perpendicular to the dimensions
        $samples = [
            [0, 0], [0, 1], [1, 0], [1, 1], // First group : a cluster at bottom-left corner in 2D
            [5, 5], [6, 5], [5, 6], [7, 5], // Second group: another cluster at the middle-right
            [3, 10],[3, 10],[3, 8], [3, 9]  // Third group : cluster at the top-middle
        ];
        $targets = [0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2];

        $classifier = new Perceptron();
        $classifier->train($samples, $targets);
        $this->assertEquals(0, $classifier->predict([0.5, 0.5]));
        $this->assertEquals(1, $classifier->predict([6.0, 5.0]));
        $this->assertEquals(2, $classifier->predict([3.0, 9.5]));
        
        return $classifier;
    }

    public function testSaveAndRestore()
    {
        // Instantinate new Percetron trained for OR problem
        $samples = [[0, 0], [1, 0], [0, 1], [1, 1]];
        $targets = [0, 1, 1, 1];
        $classifier = new Perceptron();
        $classifier->train($samples, $targets);
        $testSamples = [[0, 1], [1, 1], [0.2, 0.1]];
        $predicted = $classifier->predict($testSamples);

        $filename = 'perceptron-test-'.rand(100, 999).'-'.uniqid();
        $filepath = tempnam(sys_get_temp_dir(), $filename);
        $modelManager = new ModelManager();
        $modelManager->saveToFile($classifier, $filepath);

        $restoredClassifier = $modelManager->restoreFromFile($filepath);
        $this->assertEquals($classifier, $restoredClassifier);
        $this->assertEquals($predicted, $restoredClassifier->predict($testSamples));
    }
}
