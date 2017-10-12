# MultilayerPerceptron

A multilayer perceptron (MLP) is a feedforward artificial neural network model that maps sets of input data onto a set of appropriate outputs.

## Constructor Parameters

* $layers (array) - array with layers configuration, each value represent number of neurons in each layers
* $activationFunction (ActivationFunction) - neuron activation function

```
use Phpml\NeuralNetwork\Network\MultilayerPerceptron;
$mlp = new MultilayerPerceptron([2, 2, 1]);

// 2 nodes in input layer, 2 nodes in first hidden layer and 1 node in output layer 
```

## Methods

* setInput(array $input)
* getOutput()
* getLayers()
* addLayer(Layer $layer)

## Activation Functions

* BinaryStep
* Gaussian
* HyperbolicTangent
* Sigmoid (default)
