<?php

declare(strict_types=1);

namespace tests\Phpml\Dataset;

use Phpml\Dataset\ArrayDataset;
use PHPUnit\Framework\TestCase;

class ArrayDatasetTest extends TestCase
{
    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnInvalidArgumentsSize()
    {
        new ArrayDataset([0, 1], [0]);
    }

    public function testArrayDataset()
    {
        $dataset = new ArrayDataset(
            $samples = [[1], [2], [3], [4]],
            $labels = ['a', 'a', 'b', 'b']
        );

        $this->assertEquals($samples, $dataset->getSamples());
        $this->assertEquals($labels, $dataset->getTargets());
    }
}
