<?php

declare(strict_types=1);

namespace tests\Phpml\NeuralNetwork\ActivationFunction;

use Phpml\NeuralNetwork\ActivationFunction\Gaussian;
use PHPUnit\Framework\TestCase;

class GaussianTest extends TestCase
{
    /**
     * @param $expected
     * @param $value
     *
     * @dataProvider gaussianProvider
     */
    public function testGaussianActivationFunction($expected, $value)
    {
        $gaussian = new Gaussian();

        $this->assertEquals($expected, $gaussian->compute($value), '', 0.001);
    }

    /**
     * @return array
     */
    public function gaussianProvider()
    {
        return [
            [0.367, 1],
            [1, 0],
            [0.367, -1],
            [0, 3],
            [0, -3],
        ];
    }
}
