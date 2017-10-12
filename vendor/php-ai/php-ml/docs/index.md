# PHP-ML - Machine Learning library for PHP

[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%207.0-8892BF.svg)](https://php.net/)
[![Latest Stable Version](https://img.shields.io/packagist/v/php-ai/php-ml.svg)](https://packagist.org/packages/php-ai/php-ml)
[![Build Status](https://scrutinizer-ci.com/g/php-ai/php-ml/badges/build.png?b=develop)](https://scrutinizer-ci.com/g/php-ai/php-ml/build-status/develop)
[![Documentation Status](https://readthedocs.org/projects/php-ml/badge/?version=develop)](http://php-ml.readthedocs.org/en/develop/?badge=develop)
[![Total Downloads](https://poser.pugx.org/php-ai/php-ml/downloads.svg)](https://packagist.org/packages/php-ai/php-ml)
[![License](https://poser.pugx.org/php-ai/php-ml/license.svg)](https://packagist.org/packages/php-ai/php-ml)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/php-ai/php-ml/badges/quality-score.png?b=develop)](https://scrutinizer-ci.com/g/php-ai/php-ml/?branch=develop)

![PHP-ML - Machine Learning library for PHP](assets/php-ml-logo.png)

Fresh approach to Machine Learning in PHP. Algorithms, Cross Validation, Preprocessing, Feature Extraction and much more in one library.

PHP-ML requires PHP >= 7.0.

Simple example of classification:
```php
use Phpml\Classification\KNearestNeighbors;

$samples = [[1, 3], [1, 4], [2, 4], [3, 1], [4, 1], [4, 2]];
$labels = ['a', 'a', 'a', 'b', 'b', 'b'];

$classifier = new KNearestNeighbors();
$classifier->train($samples, $labels);

$classifier->predict([3, 2]); 
// return 'b'
```

## Documentation

To find out how to use PHP-ML follow [Documentation](http://php-ml.readthedocs.org/).

## Installation

Currently this library is in the process of developing, but You can install it with Composer:

```
composer require php-ai/php-ml
```

## Examples

Example scripts are available in a separate repository [php-ai/php-ml-examples](https://github.com/php-ai/php-ml-examples).

## Features

* Association rule Lerning
    * [Apriori](machine-learning/association/apriori/)
* Classification
    * [SVC](machine-learning/classification/svc/)
    * [k-Nearest Neighbors](machine-learning/classification/k-nearest-neighbors/)
    * [Naive Bayes](machine-learning/classification/naive-bayes/)
* Regression
    * [Least Squares](machine-learning/regression/least-squares/)
    * [SVR](machine-learning/regression/svr/)
* Clustering
    * [k-Means](machine-learning/clustering/k-means/)
    * [DBSCAN](machine-learning/clustering/dbscan/)
* Metric
    * [Accuracy](machine-learning/metric/accuracy/)
    * [Confusion Matrix](machine-learning/metric/confusion-matrix/)
    * [Classification Report](machine-learning/metric/classification-report/)
* Workflow
    * [Pipeline](machine-learning/workflow/pipeline)
* Neural Network
    * [Multilayer Perceptron](machine-learning/neural-network/multilayer-perceptron/)
    * [Backpropagation training](machine-learning/neural-network/backpropagation/)
* Cross Validation
    * [Random Split](machine-learning/cross-validation/random-split/)
    * [Stratified Random Split](machine-learning/cross-validation/stratified-random-split/)
* Preprocessing
    * [Normalization](machine-learning/preprocessing/normalization/)
    * [Imputation missing values](machine-learning/preprocessing/imputation-missing-values/)
* Feature Extraction
    * [Token Count Vectorizer](machine-learning/feature-extraction/token-count-vectorizer/)
    * [Tf-idf Transformer](machine-learning/feature-extraction/tf-idf-transformer/)
* Datasets
    * [Array](machine-learning/datasets/array-dataset/)
    * [CSV](machine-learning/datasets/csv-dataset/)
    * [Files](machine-learning/datasets/files-dataset/)
    * Ready to use:
        * [Iris](machine-learning/datasets/demo/iris/)
        * [Wine](machine-learning/datasets/demo/wine/)
        * [Glass](machine-learning/datasets/demo/glass/)
* Models management
    * [Persistency](machine-learning/model-manager/persistency/)
* Math
    * [Distance](math/distance/)
    * [Matrix](math/matrix/)
    * [Set](math/set/)
    * [Statistic](math/statistic/)
    

## Contribute

- Issue Tracker: github.com/php-ai/php-ml/issues
- Source Code: github.com/php-ai/php-ml

You can find more about contributing in [CONTRIBUTING.md](CONTRIBUTING.md).

## License

PHP-ML is released under the MIT Licence. See the bundled LICENSE file for details.

## Author

Arkadiusz Kondas (@ArkadiuszKondas)
