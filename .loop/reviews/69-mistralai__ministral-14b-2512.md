# Review 69 - mistralai/ministral-14b-2512
_asked 2026-10-01T03:59:51 - served by mistralai/ministral-14b-2512 - 18s_

## 1. Missing Input Validation in `RunnableInterface::batch()`
**Severity:** MAJOR
**Evidence:** `src/LangChain/Runnables/RunnableInterface.php` (docblock states `$options` is ignored, but no runtime validation enforces this)
**Why it matters:** The docblock explicitly states `$options` is ignored, but there's no runtime validation to enforce this. A subclass could accidentally implement `maxConcurrency` or `returnExceptions` logic, violating the documented contract.
**Suggested fix:** Add a runtime check in `RunnableInterface` base implementation:
```php
public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array {
    if ($options !== null && !empty($options)) {
        throw new \RuntimeException('`$options` is not supported in this port. Use `$config` instead.');
    }
    // ... existing logic
}
```

---

## 2. Silent Failure in `PromptTemplate::format()` for Non-String Inputs
**Severity:** MAJOR
**Evidence:** `src/LangChain/Prompts/PromptTemplate.php` (no validation for non-string `$template` in constructor)
**Why it matters:** The constructor accepts `string|list<array<string, mixed>>` for `$template`, but `format()` assumes a string. A list input would cause a fatal error when calling `Template::renderTemplate()`.
**Suggested fix:** Add validation in constructor:
```php
public function __construct(string|array $template, ...) {
    if (is_array($template) && !isset($template[0]['text'])) {
        throw new \InvalidArgumentException('Content-block template must contain at least one block with a "text" field.');
    }
    // ... rest of constructor
}
```

---

## 3. Inconsistent Partial Variable Handling in `BasePromptTemplate`
**Severity:** MAJOR
**Evidence:** `src/LangChain/Prompts/BasePromptTemplate.php` (comment about callable partials returning non-string values)
**Why it matters:** The comment reveals that callable partials returning non-string values are silently cast to strings, which could corrupt data (e.g., `['A','B']` becomes `"Array"`). This violates the documented behavior of preserving the original value.
**Suggested fix:** Remove the `(string)` cast in `mergePartialAndUserVariables()`:
```php
$partialValues[$key] = is_callable($value) ? $value() : $value;
```

---

## 4. Missing Validation for `MessagesPlaceholder` Input
**Severity:** MAJOR
**Evidence:** `src/LangChain/Prompts/MessagesPlaceholder.php` (comment about `!input` test not matching JS behavior)
**Why it matters:** The `!input` check in PHP treats empty arrays as falsy, causing `MessagesPlaceholder` to reject valid empty message lists (first turn of a conversation). This breaks the expected behavior.
**Suggested fix:** Replace `!input` with explicit null check:
```php
$absent = $input === null || $input === false || $input === '';
```

---

## 5. Undocumented Behavior in `RunnableInterface::transform()`
**Severity:** MINOR
**Evidence:** `src/LangChain/Runnables/RunnableInterface.php` (docblock states "NOT 'the first input only'" but no test enforces this)
**Why it matters:** The docblock claims `transform()` streams every input, but there's no test verifying this behavior. This could lead to silent bugs if a subclass incorrectly implements it.
**Suggested fix:** Add a test case in `RunnableTest` to verify `transform()` streams all inputs:
```php
public function testTransformStreamsAllInputs() {
    $runnable = new class implements RunnableInterface {
        public function invoke(mixed $input, ?RunnableConfig $config = null): mixed {
            return $input;
        }
        public function stream(mixed $input, ?RunnableConfig $config = null): \Generator {
            yield ['default', $input];
        }
        public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array {
            return $inputs;
        }
        public function transform(iterable $input, ?RunnableConfig $config = null): \Generator {
            foreach ($input as $item) {
                yield ['default', $item];
            }
        }
        public function getName(): string { return 'test'; }
    };
    $result = [];
    foreach ($runnable->transform([1, 2, 3]) as $chunk) {
        $result[] = $chunk[1];
    }
    $this->assertEquals([1, 2, 3], $result);
}
```