# CsvDataset

Helper class that loads data from CSV file. It extends the `ArrayDataset`.

### Constructors Parameters

* $filepath - (string) path to `.csv` file
* $features - (int) number of columns that are features (starts from first column), last column must be a label
* $headingRow - (bool) define is file have a heading row (if `true` then first row will be ignored)

```
$dataset = new CsvDataset('dataset.csv', 2, true);
```

See [ArrayDataset](array-dataset.md) for more information.
