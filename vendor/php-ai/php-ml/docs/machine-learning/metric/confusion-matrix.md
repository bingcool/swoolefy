# Confusion Matrix

Class for compute confusion matrix to evaluate the accuracy of a classification.

### Example (all targets)

Compute ConfusionMatrix for all targets.

```
use Phpml\Metric\ConfusionMatrix;

$actualTargets = [2, 0, 2, 2, 0, 1];
$predictedTargets = [0, 0, 2, 2, 0, 2];

$confusionMatrix = ConfusionMatrix::compute($actualTargets, $predictedTargets)

/*
$confusionMatrix = [
    [2, 0, 0],
    [0, 0, 1],
    [1, 0, 2],
];
*/
```

### Example (chosen targets)

Compute ConfusionMatrix for chosen targets.

```
use Phpml\Metric\ConfusionMatrix;

$actualTargets = ['cat', 'ant', 'cat', 'cat', 'ant', 'bird'];
$predictedTargets = ['ant', 'ant', 'cat', 'cat', 'ant', 'cat'];

$confusionMatrix = ConfusionMatrix::compute($actualTargets, $predictedTargets, ['ant', 'bird'])

/*
$confusionMatrix = [
    [2, 0],
    [0, 0],
];
*/
```
