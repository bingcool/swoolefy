<?php

declare(strict_types=1);

namespace tests\Phpml\NeuralNetwork\Node;

use Phpml\NeuralNetwork\Node\Bias;
use PHPUnit\Framework\TestCase;

class BiasTest extends TestCase
{
    public function testBiasOutput()
    {
        $bias = new Bias();

        $this->assertEquals(1.0, $bias->getOutput());
    }
}
