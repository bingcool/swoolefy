# Support Vector Classification

Classifier implementing Support Vector Machine based on libsvm.

### Constructor Parameters

* $kernel (int) - kernel type to be used in the algorithm (default Kernel::LINEAR)
* $cost (float) - parameter C of C-SVC (default 1.0)
* $degree (int) - degree of the Kernel::POLYNOMIAL function (default 3)
* $gamma (float) - kernel coefficient for ‘Kernel::RBF’, ‘Kernel::POLYNOMIAL’ and ‘Kernel::SIGMOID’. If gamma is ‘null’ then 1/features will be used instead.
* $coef0 (float) - independent term in kernel function. It is only significant in ‘Kernel::POLYNOMIAL’ and ‘Kernel::SIGMOID’ (default 0.0)
* $tolerance (float) - tolerance of termination criterion (default 0.001)
* $cacheSize (int) - cache memory size in MB (default 100)
* $shrinking (bool) - whether to use the shrinking heuristics (default true)
* $probabilityEstimates (bool) - whether to enable probability estimates (default false)

```
$classifier = new SVC(Kernel::LINEAR, $cost = 1000);
$classifier = new SVC(Kernel::RBF, $cost = 1000, $degree = 3, $gamma = 6);
```

### Train

To train a classifier simply provide train samples and labels (as `array`). Example:

```
use Phpml\Classification\SVC;
use Phpml\SupportVectorMachine\Kernel;

$samples = [[1, 3], [1, 4], [2, 4], [3, 1], [4, 1], [4, 2]];
$labels = ['a', 'a', 'a', 'b', 'b', 'b'];

$classifier = new SVC(Kernel::LINEAR, $cost = 1000);
$classifier->train($samples, $labels);
```

You can train the classifier using multiple data sets, predictions will be based on all the training data.

### Predict

To predict sample label use `predict` method. You can provide one sample or array of samples:

```
$classifier->predict([3, 2]);
// return 'b'

$classifier->predict([[3, 2], [1, 5]]);
// return ['b', 'a']
```
