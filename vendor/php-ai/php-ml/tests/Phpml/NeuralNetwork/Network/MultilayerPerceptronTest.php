<?php

declare(strict_types=1);

namespace tests\Phpml\NeuralNetwork\Network;

use Phpml\NeuralNetwork\Network\MultilayerPerceptron;
use Phpml\NeuralNetwork\Node\Neuron;
use PHPUnit\Framework\TestCase;

class MultilayerPerceptronTest extends TestCase
{
    public function testMultilayerPerceptronLayersInitialization()
    {
        $mlp = new MultilayerPerceptron([2, 2, 1]);

        $this->assertCount(3, $mlp->getLayers());

        $layers = $mlp->getLayers();

        // input layer
        $this->assertCount(3, $layers[0]->getNodes());
        $this->assertNotContainsOnly(Neuron::class, $layers[0]->getNodes());

        // hidden layer
        $this->assertCount(3, $layers[1]->getNodes());
        $this->assertNotContainsOnly(Neuron::class, $layers[0]->getNodes());

        // output layer
        $this->assertCount(1, $layers[2]->getNodes());
        $this->assertContainsOnly(Neuron::class, $layers[2]->getNodes());
    }

    public function testSynapsesGeneration()
    {
        $mlp = new MultilayerPerceptron([2, 2, 1]);
        $layers = $mlp->getLayers();

        foreach ($layers[1]->getNodes() as $node) {
            if ($node instanceof Neuron) {
                $synapses = $node->getSynapses();
                $this->assertCount(3, $synapses);

                $synapsesNodes = $this->getSynapsesNodes($synapses);
                foreach ($layers[0]->getNodes() as $prevNode) {
                    $this->assertContains($prevNode, $synapsesNodes);
                }
            }
        }
    }

    /**
     * @param array $synapses
     *
     * @return array
     */
    private function getSynapsesNodes(array $synapses): array
    {
        $nodes = [];
        foreach ($synapses as $synapse) {
            $nodes[] = $synapse->getNode();
        }

        return $nodes;
    }

    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnInvalidLayersNumber()
    {
        new MultilayerPerceptron([2]);
    }
}
