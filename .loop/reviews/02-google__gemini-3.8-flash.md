# Review 2 - google/gemini-3.8-flash
_asked 2026-09-30T07:32:47 - served by google/gemini-3.8-flash - 162s_

## 1. `ChatPromptTemplate::fromTemplate` drops `$options`
**Severity:** MAJOR
**Evidence:** `ChatPromptTemplate.php`: `return static::fromMessages([new HumanMessagePromptTemplate($prompt)]);`
**Why it matters:** Options passed to `fromTemplate()` (e.g. `outputParser`, `templateFormat`, `validateTemplate`) are passed to the inner `PromptTemplate` but omitted from `fromMessages()`. The returned `ChatPromptTemplate` silently loses its parser, tags, and template format.
**Suggested fix:** Pass `$options` as the second argument: `return static::fromMessages([new HumanMessagePromptTemplate($prompt)], $options);`.

## 2. `PromptTemplate::fromExamples` prepends leading separators when prefix is default
**Severity:** MAJOR
**Evidence:** `PromptTemplate.php`: `template: implode($exampleSeparator, [$prefix, ...$examples, $suffix]),`
**Why it matters:** When `$prefix` is default `''`, `implode()` includes the empty string and injects `"\n\n"` before the first example. Upstream JS conditionally excludes empty prefix (`prefix.length > 0 ? [prefix, ...examples, suffix] : [...examples, suffix]`).
**Suggested fix:** Filter empty prefix before imploding: `$parts = $prefix !== '' ? [$prefix, ...$examples, $suffix] : [...$examples, $suffix]; return new self(template: implode($exampleSeparator, $parts), ...);`.

## 3. `ChatPromptTemplate::parseImagePrompts` mutates message in place
**Severity:** MAJOR
**Evidence:** `ChatPromptTemplate.php`: `$message->content = $blocks; return $message;`
**Why it matters:** `$promptMessage` in `$this->promptMessages` is passed by object reference. Mutating `$message->content` in place permanently replaces image URL template markers with rendered values, corrupting subsequent calls on the same template instance.
**Suggested fix:** Clone `$message` before modifying its content: `$message = clone $message;` inside `parseImagePrompts()`.

## 4. `BasePromptTemplate::invoke` discards non-array inputs
**Severity:** MAJOR
**Evidence:** `BasePromptTemplate.php`: `return $this->formatPromptValue(is_array($input) ? $input : []);`
**Why it matters:** In LCEL, piping a scalar or string to a prompt with a single variable (e.g. `PromptTemplate::fromTemplate("Hello {name}")`) evaluates `is_array($input)` to false, silently replacing the user's input with `[]` and failing with missing variable errors.
**Suggested fix:** When `!is_array($input)` and `count($this->inputVariables) === 1`, wrap it: `$input = [$this->inputVariables[0] => $input];`.

## 5. `PromptTemplate::__construct` omits `additional_content_fields` from `$this->kwargs`
**Severity:** MINOR
**Evidence:** `PromptTemplate.php`: `$this->kwargs = ['input_variables' => $this->inputVariables, 'template' => $this->template, 'template_format' => $this->templateFormat];`
**Why it matters:** While `serialize()` carries `additional_content_fields`, standard LangChain serialization (`toJson()` / `jsonSerialize()`) reads `$this->kwargs()`, silently stripping provider extras like `cache_control` when serialized through standard serializers.
**Suggested fix:** Add `$this->kwargs['additional_content_fields'] = $additionalContentFields;` when non-null.