# NaiveBayes Classifier

Classifier based on applying Bayes' theorem with strong (naive) independence assumptions between the features.

### Train

To train a classifier simply provide train samples and labels (as `array`). Example:

```
$samples = [[5, 1, 1], [1, 5, 1], [1, 1, 5]];
$labels = ['a', 'b', 'c'];

$classifier = new NaiveBayes();
$classifier->train($samples, $labels);
```

You can train the classifier using multiple data sets, predictions will be based on all the training data.

### Predict

To predict sample label use `predict` method. You can provide one sample or array of samples:

```
$classifier->predict([3, 1, 1]);
// return 'a'

$classifier->predict([[3, 1, 1], [1, 4, 1]);
// return ['a', 'b']
```
