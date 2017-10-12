# Backpropagation

Backpropagation, an abbreviation for "backward propagation of errors", is a common method of training artificial neural networks used in conjunction with an optimization method such as gradient descent. 

## Constructor Parameters

* $network (Network) - network to train (for example MultilayerPerceptron instance)
* $theta (int) - network theta parameter

```
use Phpml\NeuralNetwork\Network\MultilayerPerceptron;
use Phpml\NeuralNetwork\Training\Backpropagation;

$network = new MultilayerPerceptron([2, 2, 1]);
$training = new Backpropagation($network);
```

## Training

Example of XOR training:

```
$training->train(
    $samples = [[1, 0], [0, 1], [1, 1], [0, 0]],
    $targets = [[1], [1], [0], [0]],
    $desiredError = 0.2,
    $maxIteraions = 30000
);
```
You can train the neural network using multiple data sets, predictions will be based on all the training data.
