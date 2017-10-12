<?php

declare(strict_types=1);

namespace tests\Phpml\NeuralNetwork\Node\Neuron;

use Phpml\NeuralNetwork\Node\Neuron\Synapse;
use Phpml\NeuralNetwork\Node\Neuron;
use PHPUnit\Framework\TestCase;

class SynapseTest extends TestCase
{
    public function testSynapseInitialization()
    {
        $node = $this->getNodeMock($nodeOutput = 0.5);

        $synapse = new Synapse($node, $weight = 0.75);

        $this->assertEquals($node, $synapse->getNode());
        $this->assertEquals($weight, $synapse->getWeight());
        $this->assertEquals($weight * $nodeOutput, $synapse->getOutput());

        $synapse = new Synapse($node);

        $this->assertInternalType('float', $synapse->getWeight());
    }

    public function testSynapseWeightChange()
    {
        $node = $this->getNodeMock();
        $synapse = new Synapse($node, $weight = 0.75);
        $synapse->changeWeight(1.0);

        $this->assertEquals(1.75, $synapse->getWeight());

        $synapse->changeWeight(-2.0);

        $this->assertEquals(-0.25, $synapse->getWeight());
    }

    /**
     * @param int $output
     *
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    private function getNodeMock($output = 1)
    {
        $node = $this->getMockBuilder(Neuron::class)->getMock();
        $node->method('getOutput')->willReturn($output);

        return $node;
    }
}
