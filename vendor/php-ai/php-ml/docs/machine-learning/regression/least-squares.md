# LeastSquares Linear Regression

Linear model that use least squares method to approximate solution. 

### Train

To train a model simply provide train samples and targets values (as `array`). Example:

```
$samples = [[60], [61], [62], [63], [65]];
$targets = [3.1, 3.6, 3.8, 4, 4.1];

$regression = new LeastSquares();
$regression->train($samples, $targets);
```

You can train the model using multiple data sets, predictions will be based on all the training data.

### Predict

To predict sample target value use `predict` method with sample to check (as `array`). Example:

```
$regression->predict([64]);
// return 4.06
```

### Multiple Linear Regression

The term multiple attached to linear regression means that there are two or more sample parameters used to predict target. 
For example you can use: mileage and production year to predict price of a car.  

```
$samples = [[73676, 1996], [77006, 1998], [10565, 2000], [146088, 1995], [15000, 2001], [65940, 2000], [9300, 2000], [93739, 1996], [153260, 1994], [17764, 2002], [57000, 1998], [15000, 2000]];
$targets = [2000, 2750, 15500, 960, 4400, 8800, 7100, 2550, 1025, 5900, 4600, 4400];

$regression = new LeastSquares();
$regression->train($samples, $targets);
$regression->predict([60000, 1996])
// return 4094.82
```

### Intercept and Coefficients

After you train your model you can get the intercept and coefficients array.

```
$regression->getIntercept();
// return -7.9635135135131

$regression->getCoefficients();
// return [array(1) {[0]=>float(0.18783783783783)}]
```
