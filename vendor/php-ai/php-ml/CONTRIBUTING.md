# Contributing to PHP-ML

PHP-ML is an open source project. If you'd like to contribute, please read the following text. Before I can merge your 
Pull-Request here are some guidelines that you need to follow. These guidelines exist not to annoy you, but to keep the 
code base clean, unified and future proof.

## Branch

You should only open pull requests against the develop branch.

## Unit-Tests

Please try to add a test for your pull-request. You can run the unit-tests by calling:

```
bin/phpunit
```

## Travis

GitHub automatically run your pull request through Travis CI against PHP 7. 
If you break the tests, I cannot merge your code, so please make sure that your code is working 
before opening up a Pull-Request.

## Merge

Please allow me time to review your pull requests. I will give my best to review everything as fast as possible, but cannot always live up to my own expectations.

## Coding Standards

When contributing code to PHP-ML, you must follow its coding standards. To make a long story short, here is the golden tool:

```
tools/php-cs-fixer.sh
```

This script run PHP Coding Standards Fixer with `--level=symfony` param.

More about PHP-CS-Fixer: [http://cs.sensiolabs.org/](http://cs.sensiolabs.org/)

## Documentation

Please update the documentation pages if necessary. You can find them in docs/.

---

Thank you very much again for your contribution!
