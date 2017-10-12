# Classification Report

Class for calculate main classifier metrics: precision, recall, F1 score and support.

### Report

To generate report you must provide the following parameters:

* $actualLabels - (array) true sample labels
* $predictedLabels - (array) predicted labels (e.x. from test group)

```
use Phpml\Metric\ClassificationReport;

$actualLabels = ['cat', 'ant', 'bird', 'bird', 'bird'];
$predictedLabels = ['cat', 'cat', 'bird', 'bird', 'ant'];

$report = new ClassificationReport($actualLabels, $predictedLabels);
```

### Metrics

After creating the report you can draw its individual metrics:

* precision (`getPrecision()`) - fraction of retrieved instances that are relevant
* recall (`getRecall()`) - fraction of relevant instances that are retrieved
* F1 score (`getF1score()`) - measure of a test's accuracy
* support (`getSupport()`) - count of testes samples

```
$precision = $report->getPrecision();

// $precision = ['cat' => 0.5, 'ant' => 0.0, 'bird' => 1.0];
```

### Example

```
use Phpml\Metric\ClassificationReport;

$actualLabels = ['cat', 'ant', 'bird', 'bird', 'bird'];
$predictedLabels = ['cat', 'cat', 'bird', 'bird', 'ant'];

$report = new ClassificationReport($actualLabels, $predictedLabels);

$report->getPrecision();
// ['cat' => 0.5, 'ant' => 0.0, 'bird' => 1.0]

$report->getRecall();
// ['cat' => 1.0, 'ant' => 0.0, 'bird' => 0.67]

$report->getF1score();
// ['cat' => 0.67, 'ant' => 0.0, 'bird' => 0.80]

$report->getSupport();
// ['cat' => 1, 'ant' => 1, 'bird' => 3]

$report->getAverage();
// ['precision' => 0.75, 'recall' => 0.83, 'f1score' => 0.73]

```
