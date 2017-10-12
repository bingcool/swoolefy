# Support Vector Regression

Class implementing Epsilon-Support Vector Regression based on libsvm.

### Constructor Parameters

* $kernel (int) - kernel type to be used in the algorithm (default Kernel::LINEAR)
* $degree (int) - degree of the Kernel::POLYNOMIAL function (default 3)
* $epsilon (float) -  epsilon in loss function of epsilon-SVR (default 0.1)
* $cost (float) - parameter C of C-SVC (default 1.0)
* $gamma (float) - kernel coefficient for ‘Kernel::RBF’, ‘Kernel::POLYNOMIAL’ and ‘Kernel::SIGMOID’. If gamma is ‘null’ then 1/features will be used instead.
* $coef0 (float) - independent term in kernel function. It is only significant in ‘Kernel::POLYNOMIAL’ and ‘Kernel::SIGMOID’ (default 0.0)
* $tolerance (float) - tolerance of termination criterion (default 0.001)
* $cacheSize (int) - cache memory size in MB (default 100)
* $shrinking (bool) - whether to use the shrinking heuristics (default true)

```
$regression = new SVR(Kernel::LINEAR);
$regression = new SVR(Kernel::LINEAR, $degree = 3, $epsilon=10.0);
```

### Train

To train a model simply provide train samples and targets values (as `array`). Example:

```
use Phpml\Regression\SVR;
use Phpml\SupportVectorMachine\Kernel;

$samples = [[60], [61], [62], [63], [65]];
$targets = [3.1, 3.6, 3.8, 4, 4.1];

$regression = new SVR(Kernel::LINEAR);
$regression->train($samples, $targets);
```

You can train the model using multiple data sets, predictions will be based on all the training data.

### Predict

To predict sample target value use `predict` method. You can provide one sample or array of samples:

```
$regression->predict([64])
// return 4.03
```
