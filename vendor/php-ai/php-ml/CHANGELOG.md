CHANGELOG
=========

This changelog references the relevant changes done in PHP-ML library.

* 0.4.0 (2017-02-23)
    * feature [Classification] - Ensemble Classifiers : Bagging and RandomForest by Mustafa Karabulut
    * feature [Classification] - RandomForest::getFeatureImportances() method by Mustafa Karabulut
    * feature [Classification] - Linear classifiers: Perceptron, Adaline, DecisionStump by Mustafa Karabulut
    * feature [Classification] - AdaBoost algorithm by Mustafa Karabulut
    * bug [Math] - Check if matrix is singular doing inverse by Povilas Susinskas
    * optimization - Euclidean optimization by Mustafa Karabulut

* 0.3.0 (2017-02-04)
    * feature [Persistency] - ModelManager - save and restore trained models by David Monllaó
    * feature [Classification] - DecisionTree implementation by Mustafa Karabulut
    * feature [Clustering] - Fuzzy C Means implementation by Mustafa Karabulut
    * other small fixes and code styles refactors

* 0.2.1 (2016-11-20)
    * feature [Association] - Apriori algorithm implementation
    * bug [Metric] - division by zero

* 0.2.0 (2016-08-14)
    * feature [NeuralNetwork] - MultilayerPerceptron and Backpropagation training 

* 0.1.2 (2016-07-24)
    * feature [Dataset] - FilesDataset - load dataset from files (folder names as targets)
    * feature [Metric] - ClassificationReport - report about trained classifier
    * bug [Feature Extraction] - fix problem with token count vectorizer array order
    * tests [General] - add more tests for specific conditions

* 0.1.1 (2016-07-12)
    * feature [Cross Validation] Stratified Random Split - equal distribution for targets in split
    * feature [General] Documentation - add missing pages (Pipeline, ConfusionMatrix and TfIdfTransformer) and fix links 

* 0.1.0 (2016-07-08)
    * first develop release
    * base tools for Machine Learning: Algorithms, Cross Validation, Preprocessing, Feature Extraction
    * bug [General] #7 - PHP-ML doesn't work on Mac
