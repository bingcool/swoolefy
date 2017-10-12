<?php

declare(strict_types=1);

namespace tests\Phpml\NeuralNetwork\Node;

use Phpml\NeuralNetwork\Node\Input;
use PHPUnit\Framework\TestCase;

class InputTest extends TestCase
{
    public function testInputInitialization()
    {
        $input = new Input();
        $this->assertEquals(0.0, $input->getOutput());

        $input = new Input($value = 9.6);
        $this->assertEquals($value, $input->getOutput());
    }

    public function testSetInput()
    {
        $input = new Input();
        $input->setInput($value = 6.9);

        $this->assertEquals($value, $input->getOutput());
    }
}
