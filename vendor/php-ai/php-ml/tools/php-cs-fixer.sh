#!/bin/bash
echo "Fixing src/ folder"
php-cs-fixer fix src/

echo "Fixing tests/ folder"
php-cs-fixer fix tests/
