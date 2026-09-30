# Review 85 - google/gemini-3.7-flash
_asked 2026-09-30T02:54:23 - served by google/gemini-3.7-flash - 7s_

## 1. Schema unwrap does not unwrap the root `schema` property in constructor
**Severity:** MAJOR
**Evidence:** `Schema.php`: `public function __construct(public readonly array $schema) {}` vs `errors()` calling `self::unwrap($this->schema)` but `validatesOnlyStrings()` accessing `$this->schema` directly without `unwrap()`.
**Why it matters:** If `new Schema(Schema::string())` or `Schema::from(Schema::string())` is wrapped, or if `validatesOnlyStrings()` is checked on nested subschemas, `validatesOnlyStrings()` inspects `['schema' => ['type' => 'string']]` without unwrapping, returning `false` and breaking `DynamicTool` routing.
**Suggested fix:** In `Schema::__construct()`, normalize `$this->schema` via `self::unwrap($schema)` or assign unwrapped array.

## 2. Empty array matches non-object schemas when object keys are present
**Severity:** MAJOR
**Evidence:** `Schema.php:checkSub`: `if (is_array($value) && (!\LangChain\Utils\Js::isList($value) || ($value === [] && $declaresObject)))`
**Why it matters:** When `type` is absent and a schema has both `items` (array schema) and `properties` (object schema), `$value = []` matches the object branch and returns before reaching the `items` validation branch.
**Suggested fix:** Separate the empty-array object and list validation paths so that `items` validation can still run when `properties`/`required` are also present.

## 3. `StructuredTool::mergeConfig` ignores default config when call config has non-empty fields
**Severity:** MINOR
**Evidence:** `StructuredTool.php:mergeConfig`: `if ($merged->tags === []) { $combined->tags = $defaults->tags; }`
**Why it matters:** Setting a single tag or option at call-time completely discards all default tags or options rather than merging them, unlike upstream `RunnableConfig` merges which combine collections.
**Suggested fix:** Merge array properties (`tags = array_merge($defaults->tags, $merged->tags)`, `metadata`, `options`) instead of replacing only when empty.

## 4. `Schema::matchesType` treats unknown types as matching everything
**Severity:** MINOR
**Evidence:** `Schema.php:matchesType`: `default => true,`
**Why it matters:** A typo in a schema type definition (e.g. `'strng'` or unsupported JSON schema type) quietly validates any input type as valid instead of failing validation.
**Suggested fix:** Change `default => true` in `matchesType` to `default => false`.

## 5. Duplicate docblock on `RunnableInterface::batch`
**Severity:** MINOR
**Evidence:** `RunnableInterface.php`: lines above `public function batch(...)` declare two consecutive docblocks with conflicting parameter lists (`@return list<mixed>` vs subsequent docblock).
**Why it matters:** Confuses static analyzers and IDE documentation generation; violates codebase docblock standards.
**Suggested fix:** Remove the orphan top docblock above `public function batch(...)`.