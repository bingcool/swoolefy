<?php

declare(strict_types=1);

namespace tests\Phpml\NeuralNetwork\ActivationFunction;

use Phpml\NeuralNetwork\ActivationFunction\BinaryStep;
use PHPUnit\Framework\TestCase;

class BinaryStepTest extends TestCase
{
    /**
     * @param $expected
     * @param $value
     *
     * @dataProvider binaryStepProvider
     */
    public function testBinaryStepActivationFunction($expected, $value)
    {
        $binaryStep = new BinaryStep();

        $this->assertEquals($expected, $binaryStep->compute($value));
    }

    /**
     * @return array
     */
    public function binaryStepProvider()
    {
        return [
            [1, 1],
            [1, 0],
            [0, -0.1],
        ];
    }
}
