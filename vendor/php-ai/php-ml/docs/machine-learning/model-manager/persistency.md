# Persistency

You can save trained models for future use. Persistency across requests achieved by saving and restoring serialized estimators into files.

### Example

```
use Phpml\Classification\KNearestNeighbors;
use Phpml\ModelManager;

$samples = [[1, 3], [1, 4], [2, 4], [3, 1], [4, 1], [4, 2]];
$labels = ['a', 'a', 'a', 'b', 'b', 'b'];

$classifier = new KNearestNeighbors();
$classifier->train($samples, $labels);

$filepath = '/path/to/store/the/model';
$modelManager = new ModelManager();
$modelManager->saveToFile($classifier, $filepath);

$restoredClassifier = $modelManager->restoreFromFile($filepath);
$restoredClassifier->predict([3, 2]);
// return 'b'
```
