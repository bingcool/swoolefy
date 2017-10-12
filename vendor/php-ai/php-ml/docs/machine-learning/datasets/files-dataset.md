# FilesDataset

Helper class that loads dataset from files. Use folder names as targets. It extends the `ArrayDataset`.

### Constructors Parameters

* $rootPath - (string) path to root folder that contains files dataset

```
use Phpml\Dataset\FilesDataset;

$dataset = new FilesDataset('path/to/data');
```

See [ArrayDataset](array-dataset.md) for more information.

### Example

Files structure:

```
data
    business
        001.txt
        002.txt
        ...
    entertainment
        001.txt
        002.txt
        ...
    politics
        001.txt
        002.txt
        ...
    sport
        001.txt
        002.txt
        ...
    tech
        001.txt
        002.txt
        ...
```

Load files data with `FilesDataset`: 

```
use Phpml\Dataset\FilesDataset;

$dataset = new FilesDataset('path/to/data');

$dataset->getSamples()[0][0]  // content from file path/to/data/business/001.txt
$dataset->getTargets()[0]     // business

$dataset->getSamples()[40][0] // content from file path/to/data/tech/001.txt
$dataset->getTargets()[0]     // tech
```
