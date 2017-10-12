# K-means clustering

The K-Means algorithm clusters data by trying to separate samples in n groups of equal variance, minimizing a criterion known as the inertia or within-cluster sum-of-squares. 
This algorithm requires the number of clusters to be specified.

### Constructor Parameters

* $clustersNumber - number of clusters to find
* $initialization - initialization method, default kmeans++ (see below)

```
$kmeans = new KMeans(2);
$kmeans = new KMeans(4, KMeans::INIT_RANDOM);
```

### Clustering

To divide the samples into clusters simply use `cluster` method. It's return the `array` of clusters with samples inside.

```
$samples = [[1, 1], [8, 7], [1, 2], [7, 8], [2, 1], [8, 9]];

$kmeans = new KMeans(2);
$kmeans->cluster($samples);
// return [0=>[[1, 1], ...], 1=>[[8, 7], ...]] 
```

### Initialization methods

#### kmeans++ (default)

K-means++ method selects initial cluster centers for k-mean clustering in a smart way to speed up convergence.
It use the DASV seeding method consists of finding good initial centroids for the clusters.

#### random

Random initialization method chooses completely random centroid. It get the space boundaries to avoid placing clusters centroid too far from samples data.
