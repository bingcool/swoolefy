# Iris Dataset

Most popular and widely available dataset of iris flower measurement and class names. 

### Specification

| Classes               | 3     |
| Samples per class     | 50    |
| Samples total         | 150   |
| Features per sample   | 4     |

### Load

To load Iris dataset simple use:

```
use Phpml\Dataset\Demo\Iris;

$dataset = new Iris();
```

### Several samples example

```
sepal length,sepal width,petal length,petal width,class
5.1,3.5,1.4,0.2,Iris-setosa
4.9,3.0,1.4,0.2,Iris-setosa
4.7,3.2,1.3,0.2,Iris-setosa
7.0,3.2,4.7,1.4,Iris-versicolor
6.4,3.2,4.5,1.5,Iris-versicolor
6.9,3.1,4.9,1.5,Iris-versicolor
6.3,3.3,6.0,2.5,Iris-virginica
5.8,2.7,5.1,1.9,Iris-virginica
7.1,3.0,5.9,2.1,Iris-virginica
6.3,2.9,5.6,1.8,Iris-virginicacs
```
