# Random Split

One of the simplest methods from Cross-validation is implemented as `RandomSpilt` class. Samples are split to two groups: train group and test group. You can adjust number of samples in each group.

### Constructor Parameters

* $dataset - object that implements `Dataset` interface
* $testSize - a fraction of test split (float, from 0 to 1, default: 0.3)
* $seed - seed for random generator (e.g. for tests)
 
```
$randomSplit = new RandomSplit($dataset, 0.2);
```

### Samples and labels groups

To get samples or labels from test and train group you can use getters:

```
$dataset = new RandomSplit($dataset, 0.3, 1234);

// train group
$dataset->getTrainSamples();
$dataset->getTrainLabels();

// test group
$dataset->getTestSamples();
$dataset->getTestLabels();
```
