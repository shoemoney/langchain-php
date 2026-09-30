# Review 28 - baidu/ernie-4.5-vl-424b-a47b
_asked 2026-09-30T12:49:09 - served by baidu/ernie-4.5-vl-424b-a47b - 237s_

### 1. MessagesPlaceholder throws on empty array input  
**Severity:** MAJOR  
**Evidence:** `src/LangChain/Prompts/MessagesPlaceholder.php` in `formatMessages` method. The `absent` condition (`$input === null || $input === false || $input === ''`) does not account for empty arrays. Upstream treats empty arrays as valid (truthy in JS), but PHP's empty array is falsy, causing valid empty message lists to throw.  
**Why it matters:** Breaks empty conversation history handling, a common use case.  
**Suggested fix:**  
- Modify `absent` check to exclude arrays:  
  ```php
  $absent = $input === null || $input === false || $input === '' || is_array($input) && $input === [];
  ```  
  Better: Restructure to handle arrays separately. Only treat `null`/`false`/`''` as absent. For arrays, always process (including empty arrays).

---

### 2. PromptTemplate serialization fails with output parser set  
**Severity:** MINOR  
**Evidence:** `src/LangChain/Prompts/PromptTemplate.php` in `serialize()` method throws if `outputParser` is set.  
**Why it matters:** Output parsers are unused in `PromptTemplate` but prevent serialization, limiting flexibility.  
**Suggested fix:**  
- Remove the throw and exclude `outputParser` from serialization:  
  ```php
  public function serialize(): array  
  {  
      // ... existing code ...  
      // Remove throw and simply omit outputParser from $out  
  }  
  ```

---

### 3. MessagesPlaceholder error message mentions unsupported 'developer' role  
**Severity:** MINOR  
**Evidence:** `src/LangChain/Prompts/MessagesPlaceholder.php` in `coerceStrictly()` throws with message:  
> "Unable to coerce message from array: only human, AI, system, **developer**, or tool message coercion is supported."  
**Why it matters:** 'developer' role doesn't exist in PHP port (roles are `human`, `ai`, `system`, `tool`). Incorrect message confuses users.  
**Suggested fix:**  
- Update error message:  
  ```php
  throw new InputFormatError(  
      "Unable to coerce message from array: only human, AI, system, or tool message coercion is supported."  
  );  
  ```

---

### 4. PromptTemplate::fromExamples does not enforce string examples  
**Severity:** MINOR  
**Evidence:** `src/LangChain/Prompts/PromptTemplate.php` in `fromExamples()` accepts `list<string>` but doesn't validate types. Non-string examples are silently cast.  
**Why it matters:** Type inconsistencies can lead to unexpected prompt formatting.  
**Suggested fix:**  
- Add validation:  
  ```php
  public static function fromExamples(  
      array $examples,  
      string $suffix,  
      array $inputVariables,  
      string $exampleSeparator = "\n\n",  
      string $prefix = '',  
  ): self {  
      foreach ($examples as $example) {  
          if (!is_string($example)) {  
              throw new \InvalidArgumentException('All examples must be strings.');  
          }  
      }  
      // ... existing code ...  
  }  
  ```

---

### 5. BasePromptTemplate allows duplicate keys in partialVariables  
**Severity:** MINOR  
**Evidence:** `src/LangChain/Prompts/BasePromptTemplate.php` in `mergePartialAndUserVariables()` iterates over `partialVariables` without checking for duplicate keys. Later values overwrite earlier ones silently.  
**Why it matters:** Silent overwrites can cause hard-to-debug value mismatches.  
**Suggested fix:**  
- Detect duplicates during merge:  
  ```php
  public function mergePartialAndUserVariables(array $userVariables): array  
  {  
      $partialValues = [];  
      $seenKeys = [];  
      foreach ($this->partialVariables as $key => $value) {  
          if (in_array($key, $seenKeys)) {  
              throw new \RuntimeException("Duplicate partial variable key: {$key}");  
          }  
          $seenKeys[] = $key;  
          // ... existing assignment ...  
      }  
      // ...  
  }  
  ```