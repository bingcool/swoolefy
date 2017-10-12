<?php

declare(strict_types=1);

namespace tests\Phpml\NeuralNetwork\Node;

use Phpml\NeuralNetwork\ActivationFunction\BinaryStep;
use Phpml\NeuralNetwork\Node\Neuron;
use Phpml\NeuralNetwork\Node\Neuron\Synapse;
use PHPUnit\Framework\TestCase;

class NeuronTest extends TestCase
{
    public function testNeuronInitialization()
    {
        $neuron = new Neuron();

        $this->assertEquals([], $neuron->getSynapses());
        $this->assertEquals(0.5, $neuron->getOutput());
    }

    public function testNeuronActivationFunction()
    {
        $activationFunction = $this->getMockBuilder(BinaryStep::class)->getMock();
        $activationFunction->method('compute')->with(0)->willReturn($output = 0.69);

        $neuron = new Neuron($activationFunction);

        $this->assertEquals($output, $neuron->getOutput());
    }

    public function testNeuronWithSynapse()
    {
        $neuron = new Neuron();
        $neuron->addSynapse($synapse = $this->getSynapseMock());

        $this->assertEquals([$synapse], $neuron->getSynapses());
        $this->assertEquals(0.88, $neuron->getOutput(), '', 0.01);
    }

    public function testNeuronRefresh()
    {
        $neuron = new Neuron();
        $neuron->getOutput();
        $neuron->addSynapse($this->getSynapseMock());

        $this->assertEquals(0.5, $neuron->getOutput(), '', 0.01);

        $neuron->refresh();

        $this->assertEquals(0.88, $neuron->getOutput(), '', 0.01);
    }

    /**
     * @param int $output
     *
     * @return Synapse|\PHPUnit_Framework_MockObject_MockObject
     */
    private function getSynapseMock($output = 2)
    {
        $synapse = $this->getMockBuilder(Synapse::class)->disableOriginalConstructor()->getMock();
        $synapse->method('getOutput')->willReturn($output);

        return $synapse;
    }
}
