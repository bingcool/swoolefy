<?php

declare(strict_types=1);

namespace tests\Phpml\NeuralNetwork\Network;

use Phpml\NeuralNetwork\Layer;
use Phpml\NeuralNetwork\Network\LayeredNetwork;
use Phpml\NeuralNetwork\Node\Input;
use PHPUnit\Framework\TestCase;

class LayeredNetworkTest extends TestCase
{
    public function testLayersSettersAndGetters()
    {
        $network = $this->getLayeredNetworkMock();

        $network->addLayer($layer1 = new Layer());
        $network->addLayer($layer2 = new Layer());

        $this->assertEquals([$layer1, $layer2], $network->getLayers());
    }

    public function testGetLastLayerAsOutputLayer()
    {
        $network = $this->getLayeredNetworkMock();
        $network->addLayer($layer1 = new Layer());

        $this->assertEquals($layer1, $network->getOutputLayer());

        $network->addLayer($layer2 = new Layer());
        $this->assertEquals($layer2, $network->getOutputLayer());
    }

    public function testSetInputAndGetOutput()
    {
        $network = $this->getLayeredNetworkMock();
        $network->addLayer(new Layer(2, Input::class));

        $network->setInput($input = [34, 43]);
        $this->assertEquals($input, $network->getOutput());

        $network->addLayer(new Layer(1));
        $this->assertEquals([0.5], $network->getOutput());
    }

    /**
     * @return LayeredNetwork
     */
    private function getLayeredNetworkMock()
    {
        return $this->getMockForAbstractClass(LayeredNetwork::class);
    }
}
