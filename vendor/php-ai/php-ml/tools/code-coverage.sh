#!/bin/bash
echo "Run PHPUnit with code coverage"
bin/phpunit --coverage-html .coverage
google-chrome .coverage/index.html
