# Token Count Vectorizer

Transform a collection of text samples to a vector of token counts.

### Constructor Parameters

* $tokenizer (Tokenizer) - tokenizer object (see below)
* $minDF (float) -  ignore tokens that have a samples frequency strictly lower than the given threshold. This value is also called cut-off in the literature. (default 0)

```
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\Tokenization\WhitespaceTokenizer;

$vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer());
```

### Transformation

To transform a collection of text samples use `transform` method. Example:

```
$samples = [
    'Lorem ipsum dolor sit amet dolor',
    'Mauris placerat ipsum dolor',
    'Mauris diam eros fringilla diam',
];

$vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer());
$vectorizer->transform($samples)
// return $vector = [
//    [0 => 1, 1 => 1, 2 => 2, 3 => 1, 4 => 1],
//    [5 => 1, 6 => 1, 1 => 1, 2 => 1],
//    [5 => 1, 7 => 2, 8 => 1, 9 => 1],
//];
        
```

### Vocabulary

You can extract vocabulary using `getVocabulary()` method. Example:

```
$vectorizer->getVocabulary();
// return $vocabulary = ['Lorem', 'ipsum', 'dolor', 'sit', 'amet', 'Mauris', 'placerat', 'diam', 'eros', 'fringilla'];
```

### Tokenizers

* WhitespaceTokenizer - select tokens by whitespace.
* WordTokenizer - select tokens of 2 or more alphanumeric characters (punctuation is completely ignored and always treated as a token separator).
