# Stratified Random Split

Analogously to `RandomSpilt` class samples are split to two groups: train group and test group.
Distribution of samples takes into account their targets and trying to divide them equally.
You can adjust number of samples in each group.

### Constructor Parameters

* $dataset - object that implements `Dataset` interface
* $testSize - a fraction of test split (float, from 0 to 1, default: 0.3)
* $seed - seed for random generator (e.g. for tests)
 
```
$split = new StratifiedRandomSplit($dataset, 0.2);
```

### Samples and labels groups

To get samples or labels from test and train group you can use getters:

```
$dataset = new StratifiedRandomSplit($dataset, 0.3, 1234);

// train group
$dataset->getTrainSamples();
$dataset->getTrainLabels();

// test group
$dataset->getTestSamples();
$dataset->getTestLabels();
```

### Example

```
$dataset = new ArrayDataset(
    $samples = [[1], [2], [3], [4], [5], [6], [7], [8]],
    $targets = ['a', 'a', 'a', 'a', 'b', 'b', 'b', 'b']
);

$split = new StratifiedRandomSplit($dataset, 0.5);
```

Split will have equals amount of each target. Two of the target `a` and two of `b`.
