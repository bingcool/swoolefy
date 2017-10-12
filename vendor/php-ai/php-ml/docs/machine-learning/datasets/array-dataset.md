# ArrayDataset

Helper class that holds data as PHP `array` type. Implements the `Dataset` interface which is used heavily in other classes.

### Constructors Parameters

* $samples - (array) of samples
* $labels - (array) of labels

```
$dataset = new ArrayDataset([[1, 1], [2, 1], [3, 2], [4, 1]], ['a', 'a', 'b', 'b']);
```

### Samples and labels

To get samples or labels you can use getters:

```
$dataset->getSamples();
$dataset->getTargets();
```
