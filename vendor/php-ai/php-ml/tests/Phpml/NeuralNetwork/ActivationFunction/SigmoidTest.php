<?php

declare(strict_types=1);

namespace tests\Phpml\NeuralNetwork\ActivationFunction;

use Phpml\NeuralNetwork\ActivationFunction\Sigmoid;
use PHPUnit\Framework\TestCase;

class SigmoidTest extends TestCase
{
    /**
     * @param $beta
     * @param $expected
     * @param $value
     *
     * @dataProvider sigmoidProvider
     */
    public function testSigmoidActivationFunction($beta, $expected, $value)
    {
        $sigmoid = new Sigmoid($beta);

        $this->assertEquals($expected, $sigmoid->compute($value), '', 0.001);
    }

    /**
     * @return array
     */
    public function sigmoidProvider()
    {
        return [
            [1.0, 1, 7.25],
            [2.0, 1, 3.75],
            [1.0, 0.5, 0],
            [0.5, 0.5, 0],
            [1.0, 0, -7.25],
            [2.0, 0, -3.75],
        ];
    }
}
