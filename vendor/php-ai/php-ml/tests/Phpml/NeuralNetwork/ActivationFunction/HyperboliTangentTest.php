<?php

declare(strict_types=1);

namespace tests\Phpml\NeuralNetwork\ActivationFunction;

use Phpml\NeuralNetwork\ActivationFunction\HyperbolicTangent;
use PHPUnit\Framework\TestCase;

class HyperboliTangentTest extends TestCase
{
    /**
     * @param $beta
     * @param $expected
     * @param $value
     *
     * @dataProvider tanhProvider
     */
    public function testHyperbolicTangentActivationFunction($beta, $expected, $value)
    {
        $tanh = new HyperbolicTangent($beta);

        $this->assertEquals($expected, $tanh->compute($value), '', 0.001);
    }

    /**
     * @return array
     */
    public function tanhProvider()
    {
        return [
            [1.0, 0.761, 1],
            [1.0, 0, 0],
            [1.0, 1, 4],
            [1.0, -1, -4],
            [0.5, 0.462, 1],
            [0.3, 0, 0],
        ];
    }
}
