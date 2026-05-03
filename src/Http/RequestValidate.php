<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Http;

use Common\Library\Exception\ValidateException;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Exception\DispatchException;
use Swoolefy\Exception\SystemException;

/**
 * Action DTO / BaseRequest annotation validation (rules, wildcard required, nested strict keys).
 */
class RequestValidate
{
    public function __construct(
        protected RequestInput $requestInput
    ) {
    }

    /**
     * Run Validate rules plus nested DTO unknown-key checks for one parameter's rule tree.
     *
     * @param array<string, mixed> $inputParams
     * @param array<string, mixed> $validationRules
     */
    public function validateActionParamRules(array $inputParams, array $validationRules): void
    {
        $this->validateActionParamObjectRules($inputParams, $validationRules);
        $this->validateNestedDtoStrictKeys($inputParams, $validationRules);
    }

    /**
     * Build validation rule metadata from request object attributes.
     *
     * @param string $class
     * @param array<string, bool> $visitedClasses
     * @return array<string, mixed>
     */
    public function buildActionParamValidationRuleMeta(string $class, array $visitedClasses = []): array
    {
        if (isset($visitedClasses[$class])) {
            return [];
        }

        $visitedClasses[$class] = true;
        $reflectionClass = new \ReflectionClass($class);
        $validationRules = [];
        foreach ($reflectionClass->getProperties() as $property) {
            $attributes = $property->getAttributes(ValidationRule::class);
            if (empty($attributes)) {
                continue;
            }

            foreach ($attributes as $attribute) {
                $validationRule = $attribute->newInstance();
                $rule = $validationRule->getRule();
                $itemRule = $validationRule->getItemRule();
                $itemClass = $validationRule->getItemClass();
                $nestedRules = [];
                if ($itemClass !== '') {
                    if (!class_exists($itemClass)) {
                        throw new SystemException(sprintf('Validation itemClass `%s` not found', $itemClass), \Swoole\Http\Status::INTERNAL_SERVER_ERROR);
                    }
                    $nestedRules = $this->buildActionParamValidationRuleMeta($itemClass, $visitedClasses);
                }

                if ($rule === '' && $itemRule === '' && empty($nestedRules)) {
                    continue;
                }

                $validationRules[$property->getName()] = [
                    'rule' => $rule,
                    'message' => $validationRule->getMessage(),
                    'item_rule' => $itemRule,
                    'item_message' => $validationRule->getItemMessage(),
                    'item_class' => $itemClass,
                    'nested_rules' => $nestedRules,
                ];
            }
        }

        return $validationRules;
    }

    /**
     * Reject unknown keys for nested DTO array items (strict schema).
     *
     * @param array<string, mixed> $inputParams
     * @param array<string, mixed> $validationRules
     */
    protected function validateNestedDtoStrictKeys(array $inputParams, array $validationRules): void
    {
        foreach ($validationRules as $property => $validationRule) {
            $itemClass = $validationRule['item_class'] ?? '';
            $nestedRules = $validationRule['nested_rules'] ?? [];
            if ($itemClass === '' || empty($nestedRules)) {
                continue;
            }

            if (!array_key_exists($property, $inputParams)) {
                continue;
            }

            $items = $inputParams[$property];
            if (!is_array($items)) {
                continue;
            }

            $strictKeys = $this->buildStrictNestedDtoKeys($nestedRules);
            $ignoredKeys = class_exists($itemClass)
                ? $this->buildNestedDtoUnannotatedPropertyKeys($itemClass)
                : [];
            if (empty($strictKeys) && empty($ignoredKeys)) {
                continue;
            }

            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $unknownKeys = array_diff(array_keys($item), $strictKeys, $ignoredKeys);
                if (!empty($unknownKeys)) {
                    throw new DispatchException(sprintf(
                        'Unknown fields `%s` in `%s[%s]`|||%s',
                        implode(', ', $unknownKeys),
                        $property,
                        (string)$index,
                        $this->requestInput->getSwooleRequest()->server['REQUEST_URI'] ?? ''
                    ));
                }

                foreach ($nestedRules as $nestedProperty => $nestedValidationRule) {
                    $nestedItemClass = $nestedValidationRule['item_class'] ?? '';
                    $nestedNestedRules = $nestedValidationRule['nested_rules'] ?? [];
                    if ($nestedItemClass === '' || empty($nestedNestedRules)) {
                        continue;
                    }

                    if (!array_key_exists($nestedProperty, $item)) {
                        continue;
                    }

                    $nestedItems = $item[$nestedProperty];
                    if (!is_array($nestedItems)) {
                        continue;
                    }

                    $nestedStrictKeys = $this->buildStrictNestedDtoKeys($nestedNestedRules);
                    $nestedIgnoredKeys = class_exists($nestedItemClass)
                        ? $this->buildNestedDtoUnannotatedPropertyKeys($nestedItemClass)
                        : [];
                    if (empty($nestedStrictKeys) && empty($nestedIgnoredKeys)) {
                        continue;
                    }

                    foreach ($nestedItems as $nestedIndex => $nestedItem) {
                        if (!is_array($nestedItem)) {
                            continue;
                        }

                        $nestedUnknownKeys = array_diff(array_keys($nestedItem), $nestedStrictKeys, $nestedIgnoredKeys);
                        if (!empty($nestedUnknownKeys)) {
                            throw new DispatchException(sprintf(
                                'Unknown fields `%s` in `%s[%s].%s[%s]`|||%s',
                                implode(', ', $nestedUnknownKeys),
                                $property,
                                (string)$index,
                                $nestedProperty,
                                (string)$nestedIndex,
                                $this->requestInput->getSwooleRequest()->server['REQUEST_URI'] ?? ''
                            ));
                        }
                    }
                }
            }
        }
    }

    /**
     * Only enforce unknown-field rejection for nested keys that are explicitly declared via ValidationRule.
     *
     * @param array<string, mixed> $nestedRules
     * @return array<int, string>
     */
    protected function buildStrictNestedDtoKeys(array $nestedRules): array
    {
        $keys = [];
        foreach ($nestedRules as $nestedProperty => $nestedValidationRule) {
            if ($this->nestedDtoFieldRequiresStrictShape($nestedValidationRule)) {
                $keys[] = $nestedProperty;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $nestedValidationRule
     */
    protected function nestedDtoFieldRequiresStrictShape(array $nestedValidationRule): bool
    {
        if (!empty($nestedValidationRule['rule'])) {
            return true;
        }

        if (!empty($nestedValidationRule['item_rule'])) {
            return true;
        }

        if (!empty($nestedValidationRule['item_class'])) {
            return true;
        }

        if (!empty($nestedValidationRule['nested_rules'])) {
            foreach ($nestedValidationRule['nested_rules'] as $childRule) {
                if ($this->nestedDtoFieldRequiresStrictShape($childRule)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Declared class properties without ValidationRule should be ignored by strict unknown-key checks.
     *
     * @return array<int, string>
     */
    protected function buildNestedDtoUnannotatedPropertyKeys(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }

        $reflectionClass = new \ReflectionClass($class);
        $keys = [];
        foreach ($reflectionClass->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (!empty($property->getAttributes(ValidationRule::class))) {
                continue;
            }

            $keys[] = $property->getName();
        }

        return $keys;
    }

    /**
     * Validate request/DTO attributes declared by ValidationRule annotations.
     *
     * @param array<string, mixed> $inputParams
     * @param array<string, mixed> $validationRules
     */
    protected function validateActionParamObjectRules(array $inputParams, array $validationRules): void
    {
        if (empty($validationRules)) {
            return;
        }

        $rules = $messages = [];
        foreach ($validationRules as $property => $validationRule) {
            $this->appendValidationRule($property, $validationRule, $rules, $messages);
        }

        if (!empty($rules)) {
            [$rules, $messages] = $this->expandMultiWildcardRulesAndMessages($inputParams, $rules, $messages);
            $this->enforceWildcardRequiredPresence($inputParams, $rules, $messages);
            $this->requestInput->validate($inputParams, $rules, $messages);
        }
    }

    /**
     * bingcool Validate resolves at most one `*` per field path; expand `a.*.b.*.c` using real request data.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    protected function expandMultiWildcardRulesAndMessages(array $data, array $rules, array $messages): array
    {
        $newRules = [];
        foreach ($rules as $field => $rule) {
            if (!is_string($field)) {
                $newRules[$field] = $rule;
                continue;
            }
            $fieldKey = $field;
            if (str_contains($fieldKey, '|')) {
                [$fieldKey] = explode('|', $fieldKey, 2);
            }
            if (substr_count($fieldKey, '*') < 2) {
                $newRules[$field] = $rule;
                continue;
            }
            $paths = $this->expandWildcardFieldToConcretePaths($data, explode('.', $fieldKey));
            foreach ($paths as $concrete) {
                $newRules[$concrete] = $rule;
            }
        }

        $newMessages = [];
        foreach ($messages as $msgKey => $msgText) {
            if (!is_string($msgKey) || substr_count($msgKey, '*') < 2) {
                $newMessages[$msgKey] = $msgText;
                continue;
            }
            $lastDot = strrpos($msgKey, '.');
            if ($lastDot === false) {
                $newMessages[$msgKey] = $msgText;
                continue;
            }
            $fieldTpl = substr($msgKey, 0, $lastDot);
            $suffix = substr($msgKey, $lastDot + 1);
            if (substr_count($fieldTpl, '*') < 2) {
                $newMessages[$msgKey] = $msgText;
                continue;
            }
            foreach ($this->expandWildcardFieldToConcretePaths($data, explode('.', $fieldTpl)) as $concrete) {
                $newMessages[$concrete . '.' . $suffix] = $msgText;
            }
        }

        return [$newRules, $newMessages];
    }

    /**
     * Turn `logContents.*.categories.*.cateId` into [`logContents.0.categories.0.cateId`, ...] following $data shape.
     *
     * @param mixed $data
     * @param array<int, string> $segments
     * @param array<int, string> $prefix
     * @return array<int, string>
     */
    protected function expandWildcardFieldToConcretePaths($data, array $segments, array $prefix = []): array
    {
        if ($segments === []) {
            return $prefix === [] ? [] : [implode('.', $prefix)];
        }

        $head = $segments[0];
        $tail = array_slice($segments, 1);

        if ($head === '*') {
            if (!is_array($data)) {
                return [];
            }
            $out = [];
            foreach ($data as $idx => $sub) {
                $out = array_merge(
                    $out,
                    $this->expandWildcardFieldToConcretePaths($sub, $tail, array_merge($prefix, [(string) $idx]))
                );
            }

            return $out;
        }

        if ($tail === []) {
            return [implode('.', array_merge($prefix, [$head]))];
        }

        if (!is_array($data) || !array_key_exists($head, $data)) {
            return [];
        }

        return $this->expandWildcardFieldToConcretePaths($data[$head], $tail, array_merge($prefix, [$head]));
    }

    /**
     * Fix Validate wildcard limitation: missing nested keys may be skipped by array_column extraction.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     */
    protected function enforceWildcardRequiredPresence(array $data, array $rules, array $messages): void
    {
        foreach ($rules as $field => $rule) {
            if (!is_string($field) || !str_contains($field, '*')) {
                continue;
            }

            if (!$this->validationRuleRequiresPresence($rule)) {
                continue;
            }

            $fieldKey = $field;
            if (str_contains($fieldKey, '|')) {
                [$fieldKey] = explode('|', $fieldKey, 2);
            }

            $segments = explode('.', $fieldKey);
            // Messages are flattened as `{field}.{ruleName}` (see normalizeValidationMessages), not nested under $messages[$fieldKey].
            $this->walkWildcardRequiredSegments($data, $segments, $fieldKey, $messages, []);
        }
    }

    /**
     * @param mixed $rule
     */
    protected function validationRuleRequiresPresence($rule): bool
    {
        if ($rule instanceof \Closure || $rule instanceof \Common\Library\Validate\ValidateRule) {
            return false;
        }

        if (is_string($rule)) {
            foreach (explode('|', $rule) as $item) {
                $item = trim($item);
                if ($item === '' || str_contains($item, ':')) {
                    continue;
                }

                $lower = strtolower($item);
                if ($lower === 'must' || str_starts_with($lower, 'require')) {
                    return true;
                }
            }

            return false;
        }

        if (is_array($rule)) {
            foreach ($rule as $key => $item) {
                if (is_string($key)) {
                    $lower = strtolower((string)$key);
                    if ($lower === 'must' || str_starts_with($lower, 'require')) {
                        return true;
                    }
                }

                if (is_string($item)) {
                    $lower = strtolower(trim($item));
                    if ($lower === 'must' || str_starts_with($lower, 'require')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param mixed $current
     * @param array<int, string> $segments
     * @param array<string, string> $allMessages full map from normalizeValidationMessages (`field.ruleName` => text)
     * @param array<int, string|int> $path
     */
    protected function walkWildcardRequiredSegments($current, array $segments, string $fieldKey, array $allMessages, array $path): void
    {
        if (empty($segments)) {
            return;
        }

        $head = $segments[0];
        $tail = array_slice($segments, 1);

        if ($head === '*') {
            if (!is_array($current)) {
                return;
            }

            foreach ($current as $index => $item) {
                $nextPath = $path;
                $nextPath[] = $index;
                $this->walkWildcardRequiredAfterStar($item, $tail, $fieldKey, $allMessages, $nextPath);
            }

            return;
        }

        if (!is_array($current) || !array_key_exists($head, $current)) {
            return;
        }

        $nextPath = $path;
        $nextPath[] = $head;
        $this->walkWildcardRequiredSegments($current[$head], $tail, $fieldKey, $allMessages, $nextPath);
    }

    /**
     * Handle segments right after a `*` chunk.
     *
     * @param mixed $item
     * @param array<int, string> $segments
     * @param array<string, string> $allMessages
     * @param array<int, string|int> $path
     */
    protected function walkWildcardRequiredAfterStar($item, array $segments, string $fieldKey, array $allMessages, array $path): void
    {
        if (empty($segments)) {
            return;
        }

        $head = $segments[0];
        $tail = array_slice($segments, 1);

        if ($head === '*') {
            $this->walkWildcardRequiredSegments($item, $tail, $fieldKey, $allMessages, $path);
            return;
        }

        if (empty($tail)) {
            if (!is_array($item) || !array_key_exists($head, $item)) {
                throw new ValidateException($this->buildWildcardMissingKeyMessage($fieldKey, $allMessages, $path, $head));
            }

            return;
        }

        if ($tail[0] === '*') {
            if (!is_array($item) || !array_key_exists($head, $item)) {
                throw new ValidateException($this->buildWildcardMissingKeyMessage($fieldKey, $allMessages, $path, $head));
            }

            $this->walkWildcardRequiredSegments($item[$head], $tail, $fieldKey, $allMessages, $path);
            return;
        }

        if (!is_array($item) || !array_key_exists($head, $item)) {
            throw new ValidateException($this->buildWildcardMissingKeyMessage($fieldKey, $allMessages, $path, $head));
        }

        $nextPath = $path;
        $nextPath[] = $head;
        $this->walkWildcardRequiredSegments($item[$head], $tail, $fieldKey, $allMessages, $nextPath);
    }

    /**
     * @param array<string, string> $allMessages
     * @param array<int, string|int> $path
     */
    protected function buildWildcardMissingKeyMessage(string $fieldKey, array $allMessages, array $path, string $missingKey): string
    {
        foreach (['required', 'require', 'must'] as $type) {
            $candidate = $fieldKey . '.' . $type;
            if (isset($allMessages[$candidate]) && is_string($allMessages[$candidate])) {
                return $allMessages[$candidate];
            }
        }

        $location = '';
        if (!empty($path)) {
            $location = '[' . implode('][', $path) . ']';
        }

        return sprintf('%s missing required field `%s`', $fieldKey . $location, $missingKey);
    }

    /**
     * @param array<string, mixed> $validationRule
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     */
    protected function appendValidationRule(string $property, array $validationRule, array &$rules, array &$messages): void
    {
        if (!empty($validationRule['rule'])) {
            $rules[$property] = $validationRule['rule'];
            $messages = array_merge(
                $messages,
                $this->normalizeValidationMessages($property, $validationRule['rule'], $validationRule['message'] ?? [])
            );
        }

        if (!empty($validationRule['item_rule'])) {
            $itemProperty = $property . '.*';
            $rules[$itemProperty] = $validationRule['item_rule'];
            $messages = array_merge(
                $messages,
                $this->normalizeValidationMessages($itemProperty, $validationRule['item_rule'], $validationRule['item_message'] ?? [])
            );
        }

        foreach ($validationRule['nested_rules'] ?? [] as $nestedProperty => $nestedValidationRule) {
            $this->appendValidationRule($property . '.*.' . $nestedProperty, $nestedValidationRule, $rules, $messages);
        }
    }

    /**
     * @param string|array<string, string> $message
     * @return array<string, string>
     */
    protected function normalizeValidationMessages(string $property, string $rule, string|array $message): array
    {
        if ($message === '' || $message === []) {
            return [];
        }

        if (is_string($message)) {
            return [$property . '.' . $this->getFirstValidationRuleName($rule) => $message];
        }

        $messages = [];
        foreach ($message as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (is_string($key) && str_contains($key, '.')) {
                $messages[$key] = $value;
                continue;
            }

            if (is_string($key)) {
                $messages[$property . '.' . $key] = $value;
            }
        }

        return $messages;
    }

    protected function getFirstValidationRuleName(string $rule): string
    {
        $ruleItems = explode('|', $rule);
        $ruleName = $ruleItems[0] ?? $rule;
        return explode(':', $ruleName)[0];
    }
}
