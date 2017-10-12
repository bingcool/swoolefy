<?php

declare(strict_types=1);

namespace tests\Phpml\CrossValidation;

use Phpml\CrossValidation\StratifiedRandomSplit;
use Phpml\Dataset\ArrayDataset;
use PHPUnit\Framework\TestCase;

class StratifiedRandomSplitTest extends TestCase
{
    public function testDatasetStratifiedRandomSplitWithEvenDistribution()
    {
        $dataset = new ArrayDataset(
            $samples = [[1], [2], [3], [4], [5], [6], [7], [8]],
            $labels = ['a', 'a', 'a', 'a', 'b', 'b', 'b', 'b']
        );

        $split = new StratifiedRandomSplit($dataset, 0.5);

        $this->assertEquals(2, $this->countSamplesByTarget($split->getTestLabels(), 'a'));
        $this->assertEquals(2, $this->countSamplesByTarget($split->getTestLabels(), 'b'));

        $split = new StratifiedRandomSplit($dataset, 0.25);

        $this->assertEquals(1, $this->countSamplesByTarget($split->getTestLabels(), 'a'));
        $this->assertEquals(1, $this->countSamplesByTarget($split->getTestLabels(), 'b'));
    }

    public function testDatasetStratifiedRandomSplitWithEvenDistributionAndNumericTargets()
    {
        $dataset = new ArrayDataset(
            $samples = [[1], [2], [3], [4], [5], [6], [7], [8]],
            $labels = [1, 2, 1, 2, 1, 2, 1, 2]
        );

        $split = new StratifiedRandomSplit($dataset, 0.5);

        $this->assertEquals(2, $this->countSamplesByTarget($split->getTestLabels(), 1));
        $this->assertEquals(2, $this->countSamplesByTarget($split->getTestLabels(), 2));

        $split = new StratifiedRandomSplit($dataset, 0.25);

        $this->assertEquals(1, $this->countSamplesByTarget($split->getTestLabels(), 1));
        $this->assertEquals(1, $this->countSamplesByTarget($split->getTestLabels(), 2));
    }

    /**
     * @param $splitTargets
     * @param $countTarget
     *
     * @return int
     */
    private function countSamplesByTarget($splitTargets, $countTarget): int
    {
        $count = 0;
        foreach ($splitTargets as $target) {
            if ($target === $countTarget) {
                ++$count;
            }
        }

        return $count;
    }
}
