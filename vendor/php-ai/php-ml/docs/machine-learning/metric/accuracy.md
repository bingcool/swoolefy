# Accuracy

Class for calculate classifier accuracy.

### Score

To calculate classifier accuracy score use `score` static method. Parameters:

* $actualLabels - (array) true sample labels
* $predictedLabels - (array) predicted labels (e.x. from test group)
* $normalize - (bool) normalize or not the result (default: true)

### Example

```
$actualLabels = ['a', 'b', 'a', 'b'];
$predictedLabels = ['a', 'a', 'a', 'b'];

Accuracy::score($actualLabels, $predictedLabels);
// return 0.75

Accuracy::score($actualLabels, $predictedLabels, false);
// return 3
```
