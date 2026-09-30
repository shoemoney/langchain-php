# Review 24 - dots-studio/dots-3-note-preview:free (TRUNCATED)
_finish_reason=length, reasoning 23704/24000_

Let me analyze this review packet carefully. I need to find exactly five things that would make this codebase better, with specific evidence from the code provided.

Let me look at the source code provided:

1. `RunnableInterface.php` - The interface
2. `TextSplitter.php` - The base splitter class
3. `RecursiveCharacterTextSplitter.php` - The recursive splitter
4. `TextLength.php` - Length measurement
5. `Language.php` - Language constants

Let me also look at the architecture diagram and the various status documents.

Let me look for concrete issues in the code:

1. **`TextSplitter::splitOnSeparator`** - Look at the `keepSeparator` branch. When `$separator !== ''` and `keepSeparator` is true, it uses `preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text)`. But then there's a fallback for when `$splits === false` that uses `explode($separator, $text)` - but this fallback ignores `keepSeparator`. If `preg_split` fails on malformed UTF-8, the fallback doesn't apply the lookahead logic, so the separator behavior is inconsistent. This is a real bug - the fallback path doesn't preserve `keepSeparator` semantics.

2. **`TextSplitter::mergeSplits`** - Let me look at the logic carefully. The condition for entering the merge:
```php
if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize) {
```
Wait, this is comparing against `$this->chunkSize` but then inside it checks `if ($total > $this->chunkSize)` for the oversized warning. Let me look at the upstream logic...

Actually, let me look at the `mergeSplits` more carefully. The condition checks if adding the new split would exceed chunkSize. But there's a subtle issue: the `count($currentDoc)` should probably be `count($currentDoc) - 1` if we're counting separators between documents. When joining N documents, you need N-1 separators. Let me check...

Actually wait, in the upstream TS code, the merge logic is:
```js
if (total + length + currentDoc.length * separator.length > chunkSize) {
```
Hmm, `currentDoc.length` - that's the number of documents, and each separator goes between them, so that would be `currentDoc.length - 1` separators. But upstream uses `currentDoc.length`... Let me think about this more carefully.

Actually, looking at the upstream code more carefully, in the TS version:
```js
if (total + length + currentDoc.length * separator.length > chunkSize) {
```
Wait, that doesn't seem right either. Let me think... If you have 3 documents in currentDoc, you'd join them with 2 separators. So `count($currentDoc) * mb_strlen($separator)` would overcount by one separator. But this is a port, so it should match upstream.

Actually, I need to look at the upstream TS code. The port says it's a faithful port. Let me check if the `count($currentDoc)` matches upstream's `currentDoc.length`.

Hmm, in the TS original `mergeSplits`:
```js
if (total + length + currentDoc.length * separator.length > chunkSize) {
```
Wait, I think in the TS, `currentDoc` is an array of strings, and `currentDoc.length` is the number of elements. When you join them, you use `currentDoc.length - 1` separators. But the TS code uses `currentDoc.length * separator.length`. That seems like it would overcount...

Actually, let me re-read. In the TS code from `@langchain/textsplitters`:
```js
if (total + length + currentDoc.length * separator.length > chunkSize) {
```
Hmm, but this is checking the size of the NEXT chunk if we add this split. The current document being built has `currentDoc.length` pieces, and adding one more would make it `currentDoc.length + 1` pieces, requiring `currentDoc.length` separators. So actually `currentDoc.length * separator.length` IS correct for the number of separators needed after adding the new piece! Because currently there are `currentDoc.length` pieces which would need `currentDoc.length - 1` separators, and adding one more piece means `currentDoc.length` separators total.

Wait no. Let me be more careful. Currently `currentDoc` has N pieces. To join them, you need N-1 separators. If you add the new piece, you'd have N+1 pieces needing N separators. The check is `total + length + N * separator.length` where N is `count($currentDoc)`. So it's checking: current total text length + new piece length + (number of separators after adding) = N separators. That's correct!

OK so the mergeSplits logic seems correct in that regard.

Let me look at other potential issues:

3. **`RecursiveCharacterTextSplitter::splitTextRecursive`** - The `$newSeparators` variable. When the separator is found, `$newSeparators` is set to `array_values(array_slice($separators, $i + 1))`. But when the empty string `''` is found as separator (the floor), `$newSeparators` is NOT set - it stays `null`. Then later:
```php
if ($newSeparators === null) {
    $finalChunks[] = $s;
} else {
    $otherInfo = $this->splitTextRecursive($s, $newSeparators);
    foreach ($otherInfo as $o) {
        $finalChunks[] = $o;
    }
}
```
When the floor separator `''` is used, `$newSeparators` is null, so over-budget fragments are emitted directly. This matches upstream behavior where there are no more separators to recurse with.

But wait - there's a subtle issue. When the loop finds `''` as the separator (because `str_contains($text, '')` is always true), it sets `$separator = ''` and breaks, but doesn't set `$newSeparators`. Then `$newSeparators` could still be `null` from a previous iteration... no wait, it's initialized to `null` at the start of the function. So it stays null. OK.

Actually, let me re-read the loop:
```php
$newSeparators = null;
foreach ($separators as $i => $s) {
    if ($s === '') {
        $separator = $s;
        break;
    }
    if (str_contains($text, $s)) {
        $separator = $s;
        $newSeparators = array_values(array_slice($separators, $i + 1));
        break;
    }
}
```

When `$s === ''`, it sets `$separator = ''` and breaks, leaving `$newSeparators = null`. That's correct - there are no more separators to try.

4. **`TextLength::utf16CodeUnits`** - The code says:
```php
$astral = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);
return mb_strlen($text, 'UTF-8') + ($astral === false ? 0 : $astral);
```
The comment says the fallback is correct but was reached accidentally. The code now handles it explicitly. This seems fine.

5. **`TextSplitter::splitOnSeparator` fallback issue** - Let me look at this more carefully:
```php
if ($separator !== '') {
    if ($this->keepSeparator) {
        $splits = preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text);
    } else {
        $splits = explode($separator, $text);
    }
} else {
    $splits = $text === '' ? [] : mb_str_split($text, 1, 'UTF-8');
}

if ($splits === false) {
    $splits = explode($separator, $text);
}
```

The fallback `explode($separator, $text)` doesn't respect `keepSeparator`. If `keepSeparator` is true and `preg_split` fails (due to malformed UTF-8), the fallback uses `explode` which doesn't keep the separator attached. This is an inconsistency.

But more importantly, `mb_str_split` can also return false on malformed UTF-8, and then the fallback would try `explode('', $text)` which would... actually, `explode` with empty separator is not allowed in PHP. Let me check: `explode('', $text)` - actually in PHP, `explode` with an empty separator raises a ValueError. But wait, the fallback only triggers when `$splits === false`, and `mb_str_split` returns false on failure. So if the text has malformed UTF-8 and the separator is empty, we'd get `explode('', $text)` which would throw a ValueError.

Actually wait, let me re-check. PHP's `explode` does throw `ValueError` for empty separator. But actually, looking at PHP docs more carefully, `explode('', $text)` - I need to verify. In PHP 8.x, `explode` with empty string separator... let me think. Actually, I believe in PHP 8, `explode('', ...)` throws `ValueError: explode(): Argument #2 ($separator) must not be empty`. But actually, I'm not 100% sure. Let me check - in PHP 7.x it would return false. In PHP 8.x it throws.

Actually, I just realized - looking at the code flow:
- When `$separator === ''`, we go to the else branch and call `mb_str_split($text, 1, 'UTF-8')`
- If `mb_str_split` returns false (malformed UTF-8), we fall through to the `if ($splits === false)` check
- Then we call `explode($separator, $text)` which is `explode('', $text)` - this would be an error!

This is a real bug! When the separator is empty (character-level splitting) and the text has malformed UTF-8, `mb_str_split` returns false, and then `explode('', $text)` is called which is invalid.

But wait, let me also check: does `preg_split` with the `/u` flag also return false on malformed UTF-8? Yes, it does. And then the fallback `explode($separator, $text)` would work fine for non-empty separators (just without keepSeparator semantics).

So there are two issues in `splitOnSeparator`:
1. The fallback doesn't respect `keepSeparator`
2. When separator is empty and text has malformed UTF-8, `explode('', $text)` is called which is invalid

Let me now look at other files for issues.

6. **`RecursiveCharacterTextSplitter`** - Looking at the `fromLanguage` method, it calls `new self(...)` with named arguments. But the constructor signature is:
```php
public function __construct(
    ?array $separators = null,
    ?int $chunkSize = null,
    ?int $chunkOverlap = null,
    ?bool $keepSeparator = null,
    ?callable $lengthFunction = null,
)
```
And `fromLanguage` calls:
```php
return new self(
    separators: self::getSeparatorsForLanguage($language),
    chunkSize: $chunkSize,
    chunkOverlap: $chunkOverlap,
    keepSeparator: $keepSeparator,
    lengthFunction: $lengthFunction,
);
```
This looks fine.

7. Let me look at the `mergeSplits` condition more carefully. The inner while loop:
```php
while (
    $total > $this->chunkOverlap
    || (
        $total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize
        && $total > 0
    )
) {
```

Wait, this condition is checking whether to pop documents from the front. The first condition `$total > $this->chunkOverlap` keeps popping until the total is within overlap. The second condition is: if adding the new split would still exceed chunkSize AND we have something to pop, keep popping.

But there's a subtle issue: after popping, `$total` is reduced but `$len` and `count($currentDoc)` haven't changed (they refer to the new split being added, not the current doc). So the second condition might loop forever if... no, because `$total` decreases each iteration, eventually `$total > 0` becomes false.

Actually wait, the second condition uses `$total > 0` not `$total > $this->chunkOverlap`. So if `$total` is small but `$total + $len + ... > chunkSize`, it would keep popping until `$total` reaches 0. That seems right - if the current doc is small but adding the new split would exceed chunkSize, we need to flush the current doc.

Hmm, but there's another subtlety. After the while loop, the code does:
```php
$currentDoc[] = $d;
$total += $len;
```
So the new split is added after the popping. This means the while loop is trying to make room for the new split. That's correct.

8. Let me look at `TextSplitter::createDocuments` more carefully. The `loc.lines` computation:
```php
$loc['lines'] = [
    'from' => $lineCounterIndex,
    'to' => $lineCounterIndex + $newLinesCount,
];
```
And then:
```php
$lineCounterIndex += $newLinesCount;
```

So `to` is `from + newLinesCount`. But `$newLinesCount` is the number of newlines IN the chunk. If a chunk has 3 newlines, it spans 4 lines (from, from+1, from+2, from+3). So `to` should be `from + $newLinesCount + 1`? Or is `to` exclusive?

Let me check upstream... In the TS version:
```js
loc: {
  from: lineCounterIndex,
  to: lineCounterIndex + newLinesCount,
}
```
Hmm, actually in upstream it might be `to: lineCounterIndex + newLinesCount` where `to` is exclusive. Or it could be that `newLinesCount` is computed differently.

Actually, `numberOfNewLines` counts `\n` characters. If a chunk is "line1\nline2\nline3", it has 2 newlines and spans 3 lines. If `from` is 1, then `to` would be 1+2=3, which means lines 1-2 (if exclusive) or lines 1-3 (if inclusive). Hmm, this is ambiguous but it matches upstream, so it's a faithful port.

Let me focus on finding actual defects rather than questioning design choices that match upstream.

9. Let me look at the `splitOnSeparator` method again for the `keepSeparator` case. When `keepSeparator` is true:
```php
$splits = preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text);
```
This uses a lookahead to split BEFORE each occurrence of the separator, keeping the separator attached to the following piece. Then:
```php
return array_values(array_filter($splits, static fn (string $s): bool => $s !== ''));
```
This filters out empty strings. But with the lookahead approach, the first piece (before any separator) could be empty if the text starts with the separator. That's fine, it gets filtered out.

But there's a potential issue: `preg_split` with the `/u` flag. If the text contains a lone surrogate (which can happen in JS but not in valid UTF-8), `preg_split` returns false. Then the fallback `explode($separator, $text)` doesn't keep the separator. This is the inconsistency I noted earlier.

10. Let me look at the `mergeSplits` method for another potential issue. The condition:
```php
if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize) {
```

Wait, I want to double-check this against upstream. In the TS version:
```js
if (
  total +
    length +
    currentDoc.length * separator.length >
    chunkSize
) {
```

Hmm, but `currentDoc.length` is the number of pieces currently in `currentDoc`. When we add the new piece, we'd have `currentDoc.length + 1` pieces, requiring `currentDoc.length` separators. So the formula is: `total + length + currentDoc.length * separator.length`. That checks out.

But wait, in the PHP version, `count($currentDoc)` is used. Let me verify: before adding `$d` to `$currentDoc`, `count($currentDoc)` is the current number of pieces. Adding `$d` would make it `count($currentDoc) + 1` pieces, requiring `count($currentDoc)` separators. So `count($currentDoc) * mb_strlen($separator)` is correct.

OK, I think I need to look at this from a different angle. Let me look for more concrete issues.

11. **`TextSplitter::warnOversizedChunk`** - It uses `self::$oversizedChunkWarnings[]` which is a static property. But it's called from `mergeSplits` which is a regular method. The static property means warnings accumulate across all splitter instances. There's a `clearOversizedChunkWarnings()` method, but if you create two splitters and use them, the warnings from the first will still be there when you check the second. This could be a testing issue but it's documented behavior.

Actually wait, looking more carefully at the oversized chunk warning condition:
```php
if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize) {
    if ($total > $this->chunkSize) {
        $this->warnOversizedChunk($total);
    }
```

The warning is triggered when `$total > $this->chunkSize`. But `$total` is the sum of lengths of pieces already in `$currentDoc`. The condition for entering the outer `if` is that adding the new piece would exceed chunkSize. So the warning fires when the CURRENT doc (before adding the new piece) is already over chunkSize. This means a single piece that's too large would trigger the warning.

But wait, there's a subtlety: the warning is `$total` which is the total size of the current doc BEFORE adding the new piece. But the actual oversized chunk would be the current doc when joined. The size of the joined doc would be `$total + count($currentDoc) * separator_length` (approximately). So the warning reports `$total` which doesn't include separator lengths. This could be slightly inaccurate but it matches upstream.

Actually, let me look at the upstream TS code for this:
```js
if (total > chunkSize) {
  console.warn(`Created a chunk of size ${total}, which is longer than the specified ${chunkSize}`);
}
```
Yes, upstream also warns with `total` not including separators. So this is faithful.

12. Let me look at the `splitTextRecursive` method more carefully for a potential bug:

```php
$separator = $separators === [] ? '' : $separators[count($separators) - 1];
$newSeparators = null;
foreach ($separators as $i => $s) {
    if ($s === '') {
        $separator = $s;
        break;
    }
    if (str_contains($text, $s)) {
        $separator = $s;
        $newSeparators = array_values(array_slice($separators, $i + 1));
        break;
    }
}
```

Wait, there's a subtle issue here. The initial assignment `$separator = $separators === [] ? '' : $separators[count($separators) - 1];` sets `$separator` to the LAST separator in the list (the floor). Then the loop tries to find a better separator. If none of the non-empty separators are found in the text, `$separator` stays as the last element (which could be `''` or some other separator). But if the last element is NOT `''` (which shouldn't happen for the default separators but could for custom ones), and none of the separators are found in the text, then `$separator` would be set to the last element of the list, and `$newSeparators` would be `null`.

Actually, looking at the default separators `["\n\n", "\n", ' ', '']`, the last element is `''`. So if none of the non-empty separators are found, the loop would eventually hit `$s === ''` and set `$separator = ''` and break. But the initial assignment already set `$separator` to `''` (the last element). So the behavior is the same.

But what if someone passes separators like `["\n\n", "\n"]` (without the empty floor)? Then:
- Initial: `$separator = "\n"` (last element)
- Loop: checks `"\n\n"` - not found, checks `"\n"` - not found
- Loop ends without finding anything
- `$separator` is `"\n"`, `$newSeparators` is `null`

Then `$this->splitOnSeparator($text, "\n")` is called, and if the text is still too long, `$newSeparators === null` so it's emitted directly. This seems reasonable.

But wait, there's actually a bug here. The initial assignment `$separator = $separators[count($separators) - 1]` could set `$separator` to a non-empty string. Then if the loop doesn't find any matching separator and doesn't hit the `''` case, `$separator` remains the last element. But upstream's behavior is different:

In upstream TS:
```js
let separator = separators[separators.length - 1];
let newSeparators = undefined;
for (let i = 0; i < separators.length; i++) {
  const s = separators[i];
  if (s === "") {
    separator = s;
    break;
  }
  if (text.includes(s)) {
    separator = s;
    newSeparators = separators.slice(i + 1);
    break;
  }
}
```

Wait, upstream has the same logic! The initial `separator` is the last element, and the loop tries to find a better one. If none is found, the last element is used. And `newSeparators` stays `undefined` (which is `null` in PHP). So this is faithful.

Hmm, but there's a difference: in upstream, when `separator` is the last element and it's not `''`, `newSeparators` is `undefined`, so the code would emit over-budget fragments directly. In the PHP version, same thing happens. OK.

13. Let me look at the `createDocuments` method for a potential issue with the `loc.lines` computation.

Actually, let me look at the `numberOfNewLines` call for the first chunk:
```php
if ($prevChunk === null) {
    $newLinesBeforeFirstChunk = self::numberOfNewLines($text, 0, $indexChunk);
    $lineCounterIndex += $newLinesBeforeFirstChunk;
}
```

And then:
```php
$newLinesCount = self::numberOfNewLines($chunk);
```

And:
```php
$loc['lines'] = [
    'from' => $lineCounterIndex,
    'to' => $lineCounterIndex + $newLinesCount,
];
$lineCounterIndex += $newLinesCount;
```

So for the first chunk, `$lineCounterIndex` starts at 1, then gets incremented by newlines before the first chunk. Then `from` is set to that value, `to` is `from + newLinesCount`, and then `$lineCounterIndex` is incremented by `newLinesCount`.

But wait, `$newLinesCount` is the number of newlines IN the chunk. If a chunk is "line1\nline2", it has 1 newline. The `from` might be 1, `to` would be 2. But the chunk spans lines 1 and 2. So `to` should be 2 (inclusive) or 3 (exclusive). With `to = from + newLinesCount = 1 + 1 = 2`, if `to` is inclusive, it means line 2, which is correct. If `to` is exclusive, it means up to but not including line 2, which would be wrong.

Actually, looking at upstream TS:
```js
const newLinesCount = numberOfNewLines(chunk);
loc.lines = {
  from: lineCounterIndex,
  to: lineCounterIndex + newLinesCount,
};
```

Hmm, same code. Let me think about what makes sense. If a chunk is "line1\nline2\nline3", it has 2 newlines and spans 3 lines. If `from` is 1, `to` would be 3. If `to` is inclusive, it means lines 1-3, which is correct. If `to` is exclusive, it means lines 1-2, which is wrong. So `to` must be inclusive, meaning `to = from + newLinesCount` where `newLinesCount` is the number of newlines. For a 3-line chunk with 2 newlines, `to = 1 + 2 = 3`, which is correct if inclusive.

But wait, what about a single-line chunk with no newlines? `newLinesCount = 0`, `to = from + 0 = from`. So `from = to`, meaning a single line. That's correct.

OK, this seems fine and matches upstream.

14. Let me look at the `splitOnSeparator` method one more time. I want to check the `keepSeparator` behavior with `mergeSplits`.

When `keepSeparator` is true, `splitOnSeparator` uses the lookahead to keep separators attached to the following piece. Then in `splitTextRecursive`:
```php
$mergeSeparator = $this->keepSeparator ? '' : $separator;
```

So when `keepSeparator` is true, the merge uses empty separator. This means the pieces (which already have separators attached) are just concatenated without additional separators. That makes sense.

When `keepSeparator` is false, the merge uses the original separator to join pieces back together. Also makes sense.

But there's a subtlety: in `splitTextRecursive`, when recursing into a long fragment, the recursive call uses the same `$this->keepSeparator` setting. So if `keepSeparator` is true, the recursive split also keeps separators. And the merge in the recursive call also uses `''` as the merge separator. This seems correct.

15. Let me now look at the `TextSplitter::splitOnSeparator` more carefully for the malformed UTF-8 fallback issue.

```php
if ($splits === false) {
    $splits = explode($separator, $text);
}
```

When `$separator` is `''` and `$splits` came from `mb_str_split` returning false, this would call `explode('', $text)`. In PHP 8.x, `explode` with empty separator... let me check. Actually, I need to verify this.

Looking at the PHP source code for `explode`:
- In PHP 7.x: `explode('', $text)` returns `false` and emits a warning
- In PHP 8.x: `explode('', $text)` throws `ValueError: explode(): Argument #2 ($separator) must not be empty`

So in PHP 8.x (which this project requires), calling `explode('', $text)` would throw a `ValueError`. This is a real bug!

The scenario:
1. `RecursiveCharacterTextSplitter` with default separators `["\n\n", "\n", ' ', '']`
2. Text contains malformed UTF-8
3. Eventually, the floor separator `''` is chosen
4. `splitOnSeparator($text, '')` is called
5. `mb_str_split($text, 1, 'UTF-8')` returns `false` because of malformed UTF-8
6. The fallback `explode('', $text)` throws `ValueError`

This is a real defect! But wait, let me check if `mb_str_split` actually returns false on malformed UTF-8. According to PHP docs, `mb_str_split` with a specific encoding returns `false` if the string is malformed for that encoding. So yes, this can happen.

But actually, looking at the code comment in `splitOnSeparator`:
```php
if ($splits === false) {
    // A malformed UTF-8 subject makes preg_split() bail out. Falling
    // back to a literal cut keeps the splitter usable on text that
    // survived a lossy transport, rather than silently returning [].
    $splits = explode($separator, $text);
}
```

The comment only mentions `preg_split()`, not `mb_str_split()`. So the fallback was intended for the `preg_split` case, but it also catches the `mb_str_split` case, which would cause `explode('', $text)` to throw.

This is a real bug: **`splitOnSeparator`'s fallback to `explode()` is invalid when the separator is empty, causing a `ValueError` on malformed UTF-8 text.**

Actually, wait. Let me re-read the code flow more carefully:

```php
protected function splitOnSeparator(string $text, string $separator): array
{
    if ($separator !== '') {
        if ($this->keepSeparator) {
            $splits = preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text);
        } else {
            $splits = explode($separator, $text);
        }
    } else {
        $splits = $text === '' ? [] : mb_str_split($text, 1, 'UTF-8');
    }

    if ($splits === false) {
        $splits = explode($separator, $text);
    }

    return array_values(array_filter($splits, static fn (string $s): bool => $s !== ''));
}
```

When `$separator === ''`:
- If text is empty: `$splits = []` → not false → returns `[]`
- If text is valid UTF-8: `$splits = mb_str_split(...)` → array → not false → returns filtered array
- If text is malformed UTF-8: `$splits = mb_str_split(...)` → `false` → fallback `explode('', $text)` → **ValueError!**

So yes, this is a real bug. But how likely is it? The text splitter is often used on plain text, and malformed UTF-8 is rare. But it's still a defect.

Actually, let me think about whether there's a fix. The fallback should handle the empty separator case:
```php
if ($splits === false) {
    if ($separator === '') {
        // Can't explode on empty separator; fall back to byte-level splitting
        $splits = str_split($text);
    } else {
        $splits = explode($separator, $text);
    }
}
```

Or more simply, the fallback for empty separator could just be `str_split($text)` which splits into bytes (not caring about UTF-8 validity).

But actually, looking at the code comment again, it says the fallback is for "a malformed UTF-8 subject" and it's meant to keep the splitter usable. Using `str_split` (byte-level) would be the right fallback for the empty separator case.

Hmm, but wait. Let me think about whether this is actually a finding worth reporting. The code has a documented fallback for malformed UTF-8, but the fallback itself crashes on the empty separator case. That's a real bug, but it's in an error path (malformed UTF-8) that's already unusual.

Let me look for more impactful issues.

16. Let me look at the `mergeSplits` method again. I want to check the `joinDocs` call at the end:

```php
$doc = self::joinDocs($currentDoc, $separator);
if ($doc !== null) {
    $docs[] = $doc;
}
```

But wait, in `splitTextRecursive`, the merge separator is:
```php
$mergeSeparator = $this->keepSeparator ? '' : $separator;
```

And `mergeSplits` is called with `$mergeSeparator`. But inside `mergeSplits`, the separator is used for:
1. Computing the size check: `count($currentDoc) * mb_strlen($separator, 'UTF-8')`
2. Joining: `implode($separator, $docs)`

When `keepSeparator` is true, `$mergeSeparator` is `''`, so:
- Size check: `count($currentDoc) * mb_strlen('', 'UTF-8')` = `count($currentDoc) * 0` = 0
- Join: `implode('', $docs)` = concatenation

This is correct because the separators are already attached to the pieces.

When `keepSeparator` is false, `$mergeSeparator` is the original separator, which is also correct.

17. Let me look at the `TextSplitter` constructor:
```php
public function __construct(
    ?int $chunkSize = null,
    ?int $chunkOverlap = null,
    ?bool $keepSeparator = null,
    ?callable $lengthFunction = null,
) {
    $this->chunkSize = $chunkSize ?? self::DEFAULT_CHUNK_SIZE;
    $this->chunkOverlap = $chunkOverlap ?? self::DEFAULT_CHUNK_OVERLAP;
    $this->keepSeparator = $keepSeparator ?? $this->keepSeparator;
    $this->lengthFunction = $lengthFunction
        ?? static fn (string $text): int => TextLength::utf16CodeUnits($text);

    if ($this->chunkOverlap >= $this->chunkSize) {
        throw new \InvalidArgumentException('Cannot have chunkOverlap >= chunkSize');
    }
}
```

The `$keepSeparator ?? $this->keepSeparator` pattern: if `null` is passed, it uses the default from the property. But `??` only checks for `null`, so passing `false` would correctly set it to `false`. This is fine.

But there's a subtle issue with the `??` operator and `bool`. In PHP, `null ?? $default` returns `$default`, but `false ?? $default` returns `false` (because `false` is not `null`). So this is correct.

18. Let me look at `RecursiveCharacterTextSplitter::__construct`:
```php
public function __construct(
    ?array $separators = null,
    ?int $chunkSize = null,
    ?int $chunkOverlap = null,
    ?bool $keepSeparator = null,
    ?callable $lengthFunction = null,
) {
    parent::__construct(
        chunkSize: $chunkSize,
        chunkOverlap: $chunkOverlap,
        keepSeparator: $keepSeparator,
        lengthFunction: $lengthFunction,
    );

    $this->separators = $separators ?? $this->separators;
    $this->keepSeparator = $keepSeparator ?? true;
}
```

Wait! There's a bug here! After calling `parent::__construct`, the parent constructor sets `$this->keepSeparator` based on the `$keepSeparator` parameter. Then this class OVERRIDES it with `$this->keepSeparator = $keepSeparator ?? true;`.

But the parent constructor already handled the `null` case: `$this->keepSeparator = $keepSeparator ?? $this->keepSeparator;` where `$this->keepSeparator` is the property default (`false`). So if `keepSeparator` is `null`, the parent sets it to `false` (the property default), and then the child overrides it to `true`.

But what if someone passes `keepSeparator => false` explicitly? The parent sets it to `false`, then the child sets it to `false ?? true` = `false`. OK, that works.

What if someone passes `keepSeparator => true`? Parent sets to `true`, child sets to `true ?? true` = `true`. OK.

What if someone passes `keepSeparator => null`? Parent sets to `false` (property default), child sets to `null ?? true` = `true`. This is the documented default behavior for `RecursiveCharacterTextSplitter`.

So actually, the double-setting is redundant but not wrong. The child's assignment correctly implements the documented default of `true` for this class.

Hmm, but wait. The parent constructor also validates:
```php
if ($this->chunkOverlap >= $this->chunkSize) {
    throw new \InvalidArgumentException('Cannot have chunkOverlap >= chunkSize');
}
```

This validation happens in the parent constructor, before the child's assignment. That's fine.

But there's another issue: the child's constructor doesn't pass `$separators` to the parent, which is correct since the parent doesn't have a `$separators` parameter. The child sets `$this->separators` after the parent constructor. This is fine.

19. Let me look at the `splitTextRecursive` method for another potential issue. When `$newSeparators` is set:

```php
$newSeparators = array_values(array_slice($separators, $i + 1));
```

This gets the separators AFTER the chosen one. But what if the chosen separator is the last non-empty one, and the next one is `''`? Then `$newSeparators` would be `['']`. In the recursive call, the loop would immediately hit `$s === ''` and set `$separator = ''`, leaving `$newSeparators = null`. This is correct.

But what if `$newSeparators` is an empty array? This could happen if the chosen separator is the last element AND it's `''`. But wait, when `$s === ''`, we break without setting `$newSeparators`, so it stays `null`. And when a non-empty separator is found, `array_slice($separators, $i + 1)` would give the remaining elements. If the chosen separator is the last element (not `''`), then `$newSeparators = []`. In the recursive call, `$separators === []` would be true, so `$separator = ''`. Then `splitOnSeparator($s, '')` is called. And since `$newSeparators` is `null` in the recursive call... wait, no. Let me trace through:

In the recursive call `splitTextRecursive($s, [])`:
- `$separator = $separators === [] ? '' : ...` → `''`
- `$newSeparators = null`
- Loop over empty array → doesn't execute
- `$splits = $this->splitOnSeparator($s, '')`
- Then for each split, if it's too long, `$newSeparators === null` → emit directly

This is correct behavior.

20. Let me now look at the `mergeSplits` method more carefully for a potential issue with the while loop condition.

```php
while (
    $total > $this->chunkOverlap
    || (
        $total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize
        && $total > 0
    )
) {
    $total -= $this->lengthOf($currentDoc[0]);
    array_shift($currentDoc);
}
```

Wait, there's a potential issue here. After `array_shift`, `count($currentDoc)` decreases. But `$len` is the length of the NEW split being added, not any element of `$currentDoc`. So the second condition uses the original `$len` throughout. That's correct because we're checking whether adding the new split would still exceed chunkSize.

But there's a subtle issue: after popping elements, the condition `$total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize` might still be true even when `$total` is 0, because `$len` alone might exceed `chunkSize`. In that case, the loop would keep popping until `$total > 0` is false (i.e., `$total === 0`), at which point the second condition becomes false (because `$total > 0` is false). Then the new split is added to an empty `$currentDoc`, making `$total = $len`. If `$len > chunkSize`, the next iteration of the outer foreach would trigger the oversized chunk warning.

Actually wait, let me re-read the condition:
```php
$total > $this->chunkOverlap
|| (
    $total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize
    && $total > 0
)
```

The `&&` has higher precedence than `||`, so this is:
```php
($total > $this->chunkOverlap)
|| (
    ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize)
    && ($total > 0)
)
```

So the loop continues while EITHER:
1. `$total > $chunkOverlap`, OR
2. The new total would exceed chunkSize AND we still have something to pop

When `$total` reaches 0, condition 1 is false (assuming `chunkOverlap >= 0`), and condition 2 is false because `$total > 0` is false. So the loop stops. Then the new split is added to the empty `$currentDoc`.

But what if `$len > $chunkSize`? Then after adding, `$total = $len > $chunkSize`. On the next iteration of the foreach loop, the outer `if` condition would be true, and the oversized chunk warning would fire. Then the current doc (which is just the one oversized split) would be emitted. This is correct behavior.

21. Let me now look at the `TextSplitter::createDocuments` method for the `loc.lines` computation when `$indexChunk === -1`:

```php
$indexChunk = self::indexOf($text, $chunk, $indexPrevChunk + 1);
```

If the chunk is not found, `indexOf` returns `-1`. Then:
```php
if ($prevChunk === null) {
    $newLinesBeforeFirstChunk = self::numberOfNewLines($text, 0, $indexChunk);
```

With `$indexChunk = -1`, `numberOfNewLines($text, 0, -1)` calls `slice($text, 0, -1)` which would return the text minus the last character. That seems wrong but it's an edge case where the chunk can't be found in the text.

Actually, looking at the `slice` method:
```php
protected static function slice(string $text, int $start, ?int $end = null): string
{
    $length = mb_strlen($text, 'UTF-8');
    $end ??= $length;

    if ($start < 0) {
        $start = max(0, $length + $start);
    }
    if ($end < 0) {
        $end = max(0, $length + $end);
    }
    if ($end <= $start) {
        return '';
    }

    return mb_substr($text, $start, $end - $start, 'UTF-8');
}
```

With `$end = -1`, `$end = max(0, $length - 1)`. So `slice($text, 0, -1)` returns everything except the last character. For `numberOfNewLines`, this would count newlines in everything except the last character. This is a bit odd but it's an edge case.

Actually, looking at the comment in `numberOfNewLines`:
```php
/**
 * A negative `$end` means "that far from the end", matching the JS
 * `String.prototype.slice` convention — which matters because
 * {@see self::createDocuments()} reaches here with `$indexChunk === -1` when
 * a chunk cannot be located in its source text.
 */
```

So the code acknowledges this edge case. The `-1` from `indexOf` returning "not found" gets interpreted as "one from the end" by `slice`. This is a known quirk.

22. Let me now look at the `RecursiveCharacterTextSplitter::splitTextRecursive` method for another potential issue.

Actually, I want to look at the `mergeSplits` call inside `splitTextRecursive`:

```php
if ($goodSplits !== []) {
    $mergedText = $this->mergeSplits($goodSplits, $mergeSeparator);
    foreach ($mergedText as $m) {
        $finalChunks[] = $m;
    }
    $goodSplits = [];
}
```

And later:
```php
if ($goodSplits !== []) {
    $mergedText = $this->mergeSplits($goodSplits, $mergeSeparator);
    foreach ($mergedText as $m) {
        $finalChunks[] = $m;
    }
}
```

This looks correct - it flushes good splits before recursing into a long fragment, and then flushes any remaining good splits at the end.

23. Let me look at the `Language` class. It has constants for various languages and a `getSeparatorsForLanguage` method in `RecursiveCharacterTextSplitter` that uses a match expression. But looking at the match, there's no default case for languages that ARE in the `Language` constants but somehow not in the match... actually, all 16 languages are in the match. And the default throws `InvalidArgumentException`. This seems fine.

But wait, `Language::isSupported()` checks against `SUPPORTED_LANGUAGES`, and `getSeparatorsForLanguage` has a match with all those languages plus a default throw. If someone adds a new language constant but forgets to add it to the match, `isSupported()` would return true but `getSeparatorsForLanguage` would throw. This is a potential maintenance issue but not a current bug.

24. Let me now look at the `RunnableInterface` for potential issues.

The interface has `batch()` with a docblock that mentions `$options` is `Upstream batchOptions` and is unused by default. This is documented.

The `transform()` method's docblock mentions the deliberate divergence from upstream (buffering vs per-item streaming). This is documented in PORT_STATUS.md.

25. Let me look at the `TextSplitter::splitOnSeparator` one more time. I want to check the `keepSeparator` + `preg_split` case.

```php
if ($this->keepSeparator) {
    $splits = preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text);
}
```

The regex uses a lookahead `(?=...)` to split before each occurrence of the separator. This keeps the separator attached to the following piece. But what about the `/u` flag? The `/u` flag makes the regex treat the subject as UTF-8. If the subject is malformed UTF-8, `preg_split` returns false.

Then the fallback:
```php
if ($splits === false) {
    $splits = explode($separator, $text);
}
```

This uses `explode` which doesn't keep the separator. So the `keepSeparator` behavior is silently lost for malformed UTF-8 text. This is a real inconsistency.

But more importantly, the fallback doesn't handle the empty separator case, which would crash.

Let me now think about what the five most impactful findings are.

**Finding 1: `splitOnSeparator` fallback crashes on empty separator with malformed UTF-8**

When the separator is `''` (character-level splitting) and the text contains malformed UTF-8, `mb_str_split` returns `false`, and the fallback `explode('', $text)` throws `ValueError` in PHP 8.x. This is a crash in an error-handling path.

Evidence: `TextSplitter.php` - the `splitOnSeparator` method, specifically the `if ($splits === false)` block.

**Finding 2: `splitOnSeparator` fallback silently drops `keepSeparator` semantics**

When `keepSeparator` is true and `preg_split` fails on malformed UTF-8, the fallback uses `explode` which doesn't keep separators attached. The documented behavior of `keepSeparator` is silently violated for malformed UTF-8 text.

Evidence: `TextSplitter.php` - the `splitOnSeparator` method.

**Finding 3: `RecursiveCharacterTextSplitter::__construct` double-sets `keepSeparator`**

The child constructor calls `parent::__construct()` which sets `$this->keepSeparator`, then immediately overrides it. While the net result is correct (the child's default of `true` wins), the parent constructor's validation and the redundant assignment are confusing. More importantly, if the parent constructor ever changes its default, the child's behavior would silently change.

Actually, this is more of a code smell than a bug. Let me think about whether there's an actual bug here...

The parent constructor does:
```php
$this->keepSeparator = $keepSeparator ?? $this->keepSeparator;
```
Where `$this->keepSeparator` is the property default (`false`). So if `keepSeparator` is `null`, the parent sets it to `false`.

Then the child does:
```php
$this->keepSeparator = $keepSeparator ?? true;
```
If `keepSeparator` is `null`, the child sets it to `true`.

So the net effect is: `null` → `true` (child's default), `false` → `false`, `true` → `true`. This is correct.

But the parent constructor's assignment is wasted. This is a minor code quality issue, not a bug.

**Finding 4: `mergeSplits` oversized chunk warning reports `$total` without separator lengths**

The warning reports `$total` which is the sum of piece lengths, but the actual chunk size when joined includes separator lengths. This means the reported size is smaller than the actual chunk size. However, this matches upstream behavior.

Actually, this is not a bug since it matches upstream. Let me look for something else.

**Finding 5: `TextLength::utf16CodeUnits` - the `$astral === false ? 0 : $astral` pattern**

The code already handles the `false` return from `preg_match_all`. But looking at the comment:
```
// That fallback is right — astral characters cannot be counted in text
// whose encoding is already broken — but it was reaching it by accident,
// through loose coercion, with nothing saying so. Stated explicitly:
// a damaged string measures as its code-point count.
```

The code now explicitly handles the false case. This is fine.

Let me look more carefully at the code for other issues.

**Finding 6: `TextSplitter::createDocuments` - `loc.lines.to` computation**

The `to` value is `from + newLinesCount` where `newLinesCount` is the number of newlines in the chunk. For a chunk spanning N lines, there are N-1 newlines. So `to = from + (N-1)`. If `to` is meant to be inclusive, this gives `from + N - 1` which is the last line number. That's correct for inclusive.

But wait, what if the chunk doesn't end with a newline? E.g., "line1\nline2" has 1 newline, spans 2 lines. `from = 1`, `to = 1 + 1 = 2`. If inclusive, lines 1-2. Correct.

What if the chunk ends with a newline? E.g., "line1\nline2\n" has 2 newlines, spans... well, it depends on how you count. The chunk content is "line1\nline2\n" which is 2 lines of text plus a trailing newline. `newLinesCount = 2`, `from = 1`, `to = 3`. If inclusive, lines 1-3. But there's no line 3! The trailing newline doesn't create a new line.

Hmm, but this is how upstream does it too. Let me not chase this.

**Finding 7: `RecursiveCharacterTextSplitter::splitTextRecursive` - `$newSeparators` when `''` is the separator**

When the empty string `''` is found as the separator, `$newSeparators` is not set (remains `null`). This means over-budget fragments are emitted directly without further recursion. This is correct behavior since there are no more separators to try.

But there's a subtle issue: what if the separators list is `['', '\n']` (empty string first)? The loop would immediately hit `$s === ''` and set `$separator = ''` and break. Then `$newSeparators` is `null`. This means the text is split character by character, and over-budget fragments are emitted directly. But the user might have intended `'\n'` to be tried first. However, the separators are supposed to be ordered from most to least meaningful, so `''` should be last. If a user puts it first, they get character-level splitting. This is consistent with upstream.

**Finding 8: `TextSplitter::splitOnSeparator` - empty text handling**

```php
} else {
    $splits = $text === '' ? [] : mb_str_split($text, 1, 'UTF-8');
}
```

Empty text returns `[]`, which after filtering returns `[]`. This is correct.

**Finding 9: `mergeSplits` - empty splits input**

If `$splits` is empty, the foreach loop doesn't execute, `$currentDoc` remains `[]`, and `joinDocs([], $separator)` returns `null`. So `mergeSplits([], $separator)` returns `[]`. This is correct.

**Finding 10: `mergeSplits` - all splits are empty strings**

If all splits are empty strings, they're filtered out by `splitOnSeparator`, so `mergeSplits` never sees them. But if someone calls `mergeSplits` directly with empty strings... well, `joinDocs` would trim and return null. So empty strings are effectively ignored. This seems fine.

Let me now look at the `RunnableInterface.php` for issues.

**Finding 11: `RunnableInterface::batch()` signature vs docblock**

The docblock says:
```php
/**
 * @param list<mixed>              $inputs
 * @param array<string, mixed>|null $options Upstream `batchOptions`. Unused by default.
 */
public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array;
```

The `$options` parameter is documented as unused by default. This is a deliberate divergence documented in PORT_STATUS.md. Not a bug.

**Finding 12: `RunnableInterface::transform()` docblock**

The docblock documents the deliberate divergence from upstream (per-item streaming vs buffering). This is documented in PORT_STATUS.md. Not a bug.

Let me now look at the `RecursiveCharacterTextSplitter::getSeparatorsForLanguage` for the HTML case:

```php
Language::HTML => [
    '<body>',
    '<div>',
    ...
    ' ',
    '',
],
```

The HTML separators don't start with `\n`. They're raw HTML tags. This means `str_contains($text, '<body>')` would check for the literal string `<body>`. This seems correct for HTML splitting.

But wait, the `keepSeparator` default for `RecursiveCharacterTextSplitter` is `true`. With `keepSeparator` true and HTML separators like `<body>`, the lookahead split would keep `<body>` attached to the following piece. So a chunk might start with `<body>`. This is the documented behavior.

**Finding 13: `RecursiveCharacterTextSplitter::splitTextRecursive` - `$mergeSeparator` when `keepSeparator` is true**

```php
$mergeSeparator = $this->keepSeparator ? '' : $separator;
```

When `keepSeparator` is true, `$mergeSeparator` is `''`. In `mergeSplits`, this means:
- Size check: `count($currentDoc) * mb_strlen('', 'UTF-8')` = 0
- Join: `implode('', $currentDoc)` = concatenation

This is correct because the separators are already attached to the pieces from `splitOnSeparator`.

But wait, there's a subtlety. In `splitOnSeparator` with `keepSeparator` true:
```php
$splits = preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text);
```

This splits BEFORE each separator, so each piece (except the first) starts with the separator. When merged with `implode('', $pieces)`, the pieces are concatenated directly, preserving the separators at the start of each piece. This is correct.

But what about the `mergeSplits` size check? With `$mergeSeparator = ''`:
```php
$total + $len + count($currentDoc) * mb_strlen('', 'UTF-8') > $this->chunkSize
```
This becomes `$total + $len > $this->chunkSize` (since `count($currentDoc) * 0 = 0`). This checks if the total text length (including separators already attached to pieces) plus the new piece exceeds chunkSize. This is correct.

**Finding 14: `RecursiveCharacterTextSplitter::splitTextRecursive` - flushing `$goodSplits` before recursing**

```php
if ($goodSplits !== []) {
    $mergedText = $this->mergeSplits($goodSplits, $mergeSeparator);
    foreach ($mergedText as $m) {
        $finalChunks[] = $m;
    }
    $goodSplits = [];
}
```

This flushes the good splits before recursing into a long fragment. The comment says "so they leave as one chunk rather than being interleaved with the deep split." This is correct behavior.

But there's a potential issue: after flushing `$goodSplits`, the long fragment is processed recursively. But the recursive call might produce multiple chunks. These are added to `$finalChunks`. Then the loop continues with the next split. This is correct.

**Finding 15: `RecursiveCharacterTextSplitter::splitTextRecursive` - the `$newSeparators === null` case**

When `$newSeparators === null` (meaning no more separators to try), over-budget fragments are emitted directly:
```php
if ($newSeparators === null) {
    $finalChunks[] = $s;
}
```

But wait, this means a fragment that's larger than `chunkSize` is emitted as-is. The `mergeSplits` method would have warned about it (via `warnOversizedChunk`), but `splitTextRecursive` bypasses `mergeSplits` for this case. So the oversized chunk warning is never emitted for fragments that are too large even after all separators are exhausted.

Actually, looking at the flow more carefully: when `$newSeparators === null`, the fragment `$s` is emitted directly without going through `mergeSplits`. So `warnOversizedChunk` is never called for this fragment. But the fragment IS over budget. This means the oversized chunk warning system is incomplete for the recursive splitter.

Hmm, but actually, `mergeSplits` is called on `$goodSplits` (the short siblings), not on the long fragment. The long fragment is emitted directly. So if a long fragment is over budget, no warning is emitted. This seems like a gap.

But wait, looking at the upstream TS code:
```js
if (newSeparators === null) {
  // Out of separators, this fragment is as small as it gets
  finalChunks.push(s);
} else {
  const otherInfo = this.splitTextRecursive(s, newSeparators);
  for (const o of otherInfo) {
    finalChunks.push(o);
  }
}
```

Upstream also emits the fragment directly without warning. So this matches upstream. The warning only fires inside `mergeSplits` when a chunk being built exceeds `chunkSize`.

Actually, let me re-read `mergeSplits` more carefully. The warning fires when:
```php
if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize) {
    if ($total > $this->chunkSize) {
        $this->warnOversizedChunk($total);
    }
```

So the warning fires when the current doc (before adding the new split) is already over `chunkSize`. This happens when a single split is too large to fit with any other splits. But in `splitTextRecursive`, large splits are either recursed into (if there are more separators) or emitted directly (if there are no more separators). They never go through `mergeSplits` as part of `$goodSplits` because they're filtered out by the `if ($this->lengthOf($s) < $this->chunkSize)` check.

Wait, actually, the condition is `< $this->chunkSize`, not `<= $this->chunkSize`. So a split that's exactly `chunkSize` would be treated as "good" and added to `$goodSplits`. Then in `mergeSplits`, if it's the only split, `$total` would be `chunkSize`, and the condition `$total > $this->chunkSize` would be false, so no warning. But if there are other splits, the total might exceed `chunkSize`, triggering the warning.

Hmm, this is getting complex. Let me focus on finding clear, verifiable defects.

Let me re-examine the `splitOnSeparator` method one more time. I think the empty separator fallback issue is the most concrete bug I've found.

Actually, let me look at the code one more time:

```php
if ($splits === false) {
    $splits = explode($separator, $text);
}
```

When `$separator` is `''`:
- `explode('', $text)` in PHP 8 throws `ValueError`

This is a definite bug. Let me verify by checking PHP behavior.

In PHP 8.0+, `explode('', $text)` throws:
```
ValueError: explode(): Argument #2 ($separator) must not be empty
```

Yes, this is a confirmed bug.

Now, let me also check: can `mb_str_split` actually return false? According to PHP docs:
> Returns an array of substrings, or false on failure.

And from the PHP source: `mb_str_split` returns false when the encoding is invalid and `MB_SUBSTRING_INVALID` behavior is set, or when the length is invalid. Actually, with valid length (1) and potentially invalid UTF-8, it depends on the `mbstring.func_overload` setting and the specific behavior.

Actually, looking at PHP's `mb_str_split` implementation more carefully: when the input is malformed UTF-8 and the encoding is specified, it returns false. This is documented behavior.

So the scenario is:
1. Text contains malformed UTF-8 (e.g., from a lossy transport)
2. `RecursiveCharacterTextSplitter` eventually falls back to `''` separator
3. `splitOnSeparator($text, '')` is called
4. `mb_str_split($text, 1, 'UTF-8')` returns `false`
5. `explode('', $text)` throws `ValueError`

This is a real crash bug.

Now let me also check the `preg_split` fallback:
1. `keepSeparator` is true
2. Text contains malformed UTF-8
3. `preg_split('/.../u', $text)` returns `false`
4. `explode($separator, $text)` is used instead
5. But `explode` doesn't keep the separator attached

This is a silent behavior change, not a crash. But it's still a bug.

Let me now look for other issues in the codebase.

**Looking at `TextSplitter::mergeSplits` again:**

```php
while (
    $total > $this->chunkOverlap
    || (
        $total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize
        && $total > 0
    )
) {
```

Wait, I want to check this against upstream more carefully. In the TS version:

```js
while (
  total > chunkOverlap ||
  (total + length + currentDoc.length * separator.length > chunkSize &&
    total > 0)
) {
  total -= lengthOf(currentDoc[0]);
  currentDoc.shift();
}
```

Hmm, wait. In the TS version, `currentDoc.length` is used. But `currentDoc` is the array BEFORE adding the new split. So `currentDoc.length` is the number of pieces currently in the doc. When we add the new split, we'd have `currentDoc.length + 1` pieces, needing `currentDoc.length` separators. So the formula `total + length + currentDoc.length * separator.length` is correct.

But in the PHP version, `count($currentDoc)` is used, which is the same as `currentDoc.length`. So this matches.

Wait, but there's a subtle difference. In the TS version, after `currentDoc.shift()`, `currentDoc.length` decreases. So on the next iteration of the while loop, `currentDoc.length * separator.length` is smaller. In the PHP version, `count($currentDoc)` also decreases after `array_shift`. So this matches.

OK, the mergeSplits logic seems correct.

**Looking at `TextSplitter::createDocuments` again:**

```php
$indexChunk = self::indexOf($text, $chunk, $indexPrevChunk + 1);
```

And then:
```php
if ($prevChunk === null) {
    $newLinesBeforeFirstChunk = self::numberOfNewLines($text, 0, $indexChunk);
    $lineCounterIndex += $newLinesBeforeFirstChunk;
}
```

If `$indexChunk === -1` (chunk not found), `numberOfNewLines($text, 0, -1)` is called. The `slice` method with `$end = -1` returns the text minus the last character. Then `substr_count($textSection, "\n")` counts newlines in that section. This is a bit odd but it's an edge case.

Actually, wait. Looking at `slice`:
```php
if ($end < 0) {
    $end = max(0, $length + $end);
}
```

With `$end = -1` and `$length` being the text length, `$end = max(0, $length - 1)`. So `slice($text, 0, -1)` returns `mb_substr($text, 0, ($length - 1) - 0, 'UTF-8')` = `mb_substr($text, 0, $length - 1, 'UTF-8')`. This is the text without the last character.

But wait, `$indexChunk` is the position of the chunk in the text. If it's `-1`, the chunk wasn't found. Using `-1` as an end position for `slice` is a quirk of the JS `slice` convention. The comment acknowledges this:
```php
* {@see self::createDocuments()} reaches here with `$indexChunk === -1` when
* a chunk cannot be located in its source text.
```

So this is a known edge case. The line count might be slightly off, but it's an unusual situation.

**Let me now look at the `RecursiveCharacterTextSplitter::getSeparatorsForLanguage` method for the RUST case:**

```php
Language::RUST => [
    "\nfn ",
    "\nconst ",
    "\nlet ",
    "\nif ",
    "\nwhile ",
    "\nfor ",
    "\nloop ",
    "\nmatch ",
    "\nconst ",  // <-- DUPLICATE!
    "\n\n",
    "\n",
    ' ',
    '',
],
```

Wait, `"\nconst "` appears twice! Once at index 1 and again at index 7. This is a duplicate separator. It's not a bug per se (the second occurrence would never be reached since the first one matches first), but it's clearly a copy-paste error. The second `"\nconst "` should probably be `"\nenum "` or something else.

Actually, looking at the upstream TS code for Rust separators:
```js
Language.RUST => [
    "\nfn ",
    "\nconst ",
    "\nlet ",
    // Split along control flow statements
    "\nif ",
    "\nwhile ",
    "\nfor ",
    "\nloop ",
    "\nmatch ",
    "\nconst ",  // This is a duplicate in upstream too!
    // Split by the normal type of lines
    "\n\n",
    "\n",
    ' ',
    '',
],
```

Wait, is this actually in upstream? Let me think... The upstream TS code for `RecursiveCharacterTextSplitter` does have this exact list. The duplicate `"\nconst "` is in the upstream source. It's harmless because the first `"\nconst "` would match first, and the second one would never be reached. But it's clearly a mistake in the upstream source that was faithfully ported.

Actually, I just realized - looking at the SCALA case:
```php
Language::SCALA => [
    "\nclass ",
    "\nobject ",
    "\ndef ",
    "\nval ",
    "\nvar ",
    "\nif ",
    "\nfor ",
    "\nwhile ",
    "\nmatch ",
    "\ncase ",
    "\n\n",
    "\n",
    ' ',
    '',
],
```

And the SWIFT case:
```php
Language::SWIFT => [
    "\nfunc ",
    "\nclass ",
    "\nstruct ",
    "\nenum ",
    "\nif ",
    "\nfor ",
    "\nwhile ",
    "\ndo ",
    "\nswitch ",
    "\ncase ",
    "\n\n",
    "\n",
    ' ',
    '',
],
```

These look correct. The RUST duplicate is likely a faithful port of an upstream bug.

OK, let me now focus on finding the five most impactful findings. Let me look at the code with fresh eyes.

**Finding A: `splitOnSeparator` crashes on empty separator with malformed UTF-8**

This is the most concrete bug. When `separator` is `''` and `mb_str_split` returns `false` (malformed UTF-8), the fallback `explode('', $text)` throws `ValueError` in PHP 8.

**Finding B: `splitOnSeparator` fallback silently drops `keepSeparator`**

When `keepSeparator` is true and `preg_split` fails, the fallback `explode` doesn't keep separators. This is a silent behavior change.

**Finding C: `RecursiveCharacterTextSplitter::__construct` redundantly sets `keepSeparator`**

The child constructor overrides the parent's `keepSeparator` assignment. While the net result is correct, it's confusing and fragile.

**Finding D: `mergeSplits` while loop condition uses `$len` which doesn't change**

The while loop condition references `$len` (the length of the new split being added), but `$len` doesn't change inside the loop. This is correct (we're checking if adding the new split would still exceed chunkSize after popping), but it could be confusing to readers.

Actually, this is not a bug. It's correct behavior.

**Finding E: `TextSplitter::createDocuments` line count with unfound chunk**

When a chunk can't be found in the text (`indexOf` returns -1), the line count uses `slice($text, 0, -1)` which is the text minus the last character. This gives a slightly wrong line count. But this is a known edge case documented in the code.

Let me now think about what else might be wrong. Let me look at the `TextSplitter` class more carefully.

**The `splitOnSeparator` method's `keepSeparator` behavior with `explode`:**

When `keepSeparator` is false and the separator is non-empty:
```php
$splits = explode($separator, $text);
```

This is correct - `explode` splits ON the separator, removing it. The pieces don't have the separator attached.

When `keepSeparator` is true and the separator is non-empty:
```php
$splits = preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text);
```

This splits BEFORE each separator, keeping it attached to the following piece. Correct.

But there's a subtle issue with the `preg_split` approach: if the text starts with the separator, the first piece (before the first separator) is empty. It gets filtered out by `array_filter`. But what if the text is just the separator? E.g., `text = "\n\n"` and `separator = "\n\n"`. Then `preg_split` would produce `['', '']` (split before the separator at position 0, and split before the end). After filtering, this becomes `[]`. So a text that IS the separator produces no chunks. Is this correct?

In upstream, `explode("\n\n", "\n\n")` produces `['', '']`, which after filtering becomes `[]`. So yes, this matches upstream.

**Let me now look at the `mergeSplits` method for the separator count in the size check:**

```php
if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize) {
```

Wait, I want to verify this against upstream one more time. In the TS version:

```js
if (
  total +
    length +
    currentDoc.length * separator.length >
    chunkSize
) {
```

Hmm, but `currentDoc.length` is the number of pieces currently in `currentDoc`. When we add the new piece, we'd have `currentDoc.length + 1` pieces. The number of separators needed to join them is `currentDoc.length`. So the formula is correct.

But wait, in the PHP version, after `array_shift($currentDoc)`, `count($currentDoc)` decreases. So on the next iteration of the while loop, the separator count is smaller. This matches the TS version where `currentDoc.length` decreases after `shift()`.

OK, I'm convinced the mergeSplits logic is correct.

**Let me now look at the `TextSplitter` class for the `splitDocuments` method:**

```php
public function splitDocuments(
    array $documents,
    ?TextSplitterChunkHeaderOptions $options = null
): array {
    $selectedDocuments = array_values($documents);

    $texts = array_map(
        static fn (Document $doc): string => $doc->pageContent,
        $selectedDocuments,
    );
    $metadatas = array_map(
        static fn (Document $doc): array => $doc->metadata,
        $selectedDocuments,
    );

    return $this->createDocuments($texts, $metadatas, $options);
}
```

This looks correct. It extracts pageContent and metadata from each document and passes them to `createDocuments`.

**Let me now look at the `TextSplitter::transformDocuments` method:**

```php
public function transformDocuments(
    array $documents,
    ?TextSplitterChunkHeaderOptions $options = null
): array {
    return $this->splitDocuments($documents, $options);
}
```

This just delegates to `splitDocuments`. Correct.

**Let me now look at the `TextSplitter::createDocuments` method for the metadata handling:**

```php
$metadata = $metadatas[$i] ?? [];
$loc = isset($metadata['loc']) && is_array($metadata['loc'])
    ? $metadata['loc']
    : [];
$loc['lines'] = [
    'from' => $lineCounterIndex,
    'to' => $lineCounterIndex + $newLinesCount,
];
$metadata['loc'] = $loc;
```

This sets `loc.lines` on the metadata. If the metadata already had a `loc` key, it preserves other keys under `loc` and overwrites `lines`. This seems correct.

**Let me now look at the `RecursiveCharacterTextSplitter::splitTextRecursive` method for the case where `$separator` is `''` and `$newSeparators` is `null`:**

When the empty string is the separator:
- `$separator = ''`
- `$newSeparators = null` (not set in the loop)
- `$splits = $this->splitOnSeparator($text, '')` → character-level splits
- For each split that's too long: `$newSeparators === null` → emit directly

This means a single character that's too long (impossible since characters are 1 unit) would be emitted directly. But since characters are always length 1, they'd always be added to `$goodSplits` and merged. So this case doesn't actually arise for the empty separator.

Wait, but what about astral characters? They count as 2 UTF-16 code units. If `chunkSize` is 1, an astral character would be too long. But `chunkSize` must be > `chunkOverlap` >= 0, so `chunkSize` must be at least 1. With `chunkSize = 1`, an astral character (length 2) would be too long. It would be emitted directly since `$newSeparators === null`. This is correct behavior.

**Let me now look at the `TextSplitter` class for the `lcId` method:**

```php
public static function lcId(): array
{
    return ['langchain', 'document_transformers', 'text_splitters'];
}
```

And in `RecursiveCharacterTextSplitter`:
```php
public static function lcId(): array
{
    return ['langchain', 'document_transformers', 'text_splitters', 'RecursiveCharacterTextSplitter'];
}
```

These are for serialization. They look correct.

**Let me now look at the `Language` class for the `isSupported` method:**

```php
public static function isSupported(string $language): bool
{
    self::$lookup ??= array_fill_keys(self::SUPPORTED_LANGUAGES, true);

    return isset(self::$lookup[$language]);
}
```

This uses a lazy-initialized static lookup table. It's correct.

**Now let me look at the `TextLength::utf16CodeUnits` method one more time:**

```php
public static function utf16CodeUnits(string $text): int
{
    if ($text === '') {
        return 0;
    }

    $astral = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);

    return mb_strlen($text, 'UTF-8') + ($astral === false ? 0 : $astral);
}
```

The regex `[\x{10000}-\x{10FFFF}]` matches characters in the astral plane. But wait, `\x{10000}` to `\x{10FFFF}` covers all supplementary planes. However, `preg_match_all` with the `/u` flag returns `false` on malformed UTF-8. The code handles this with `$astral === false ? 0 : $astral`.

But there's a subtle issue: `mb_strlen($text, 'UTF-8')` also returns... actually, `mb_strlen` with a specific encoding returns the number of characters, and on malformed UTF-8, it might return the count of valid characters or the total length depending on the `mbstring.func_overload` and `mbstring.strict_encoding` settings. Actually, in PHP 8.x, `mb_strlen` with invalid UTF-8 returns the byte length (or something else depending on the submethod). Let me check...

Actually, `mb_strlen($text, 'UTF-8')` with malformed UTF-8: the behavior depends on the `mbstring.substitute_char` setting. By default, it might return `false` or the byte length. But the code doesn't check for `false` from `mb_strlen`.

Hmm, actually, in PHP 8.x, `mb_strlen` with an invalid encoding returns `false` if `mbstring.strict_encoding` is set, or the byte length otherwise. But the return type is `int|string|false` depending on the version. Actually, in modern PHP, `mb_strlen` returns `int` (0 or positive) even for invalid UTF-8, because it counts bytes or uses a substitution character.

Wait, let me check the PHP docs. `mb_strlen` returns `int` the number of characters. For malformed UTF-8, it might return the byte count or use a substitution. But it doesn't return `false` in PHP 8.x. So `mb_strlen($text, 'UTF-8') + ($astral === false ? 0 : $astral)` would work even with malformed UTF-8, giving the byte count (since astral characters can't be counted in broken text).

The code comment says:
```
// That fallback is right — astral characters cannot be counted in text
// whose encoding is already broken — but it was reaching it by accident,
// through loose coercion, with nothing saying so. Stated explicitly:
// a damaged string measures as its code-point count.
```

Wait, it says "code-point count" but `mb_strlen` on malformed UTF-8 gives the byte count, not the code-point count. Hmm, actually, the behavior of `mb_strlen` on malformed UTF-8 depends on the PHP version and settings. In some configurations, it counts bytes; in others, it uses a substitution character.

But this is getting into the weeds. The code handles the `false` return from `preg_match_all` explicitly, which is the documented fix. The `mb_strlen` behavior on malformed UTF-8 is a separate concern.

**Let me now look at the `RecursiveCharacterTextSplitter::splitTextRecursive` method for the `$goodSplits` flushing:**

```php
foreach ($splits as $s) {
    if ($this->lengthOf($s) < $this->chunkSize) {
        $goodSplits[] = $s;
    } else {
        // Flush the short siblings before recursing
        if ($goodSplits !== []) {
            $mergedText = $this->mergeSplits($goodSplits, $mergeSeparator);
            foreach ($mergedText as $m) {
                $finalChunks[] = $m;
            }
            $goodSplits = [];
        }

        if ($newSeparators === null) {
            $finalChunks[] = $s;
        } else {
            $otherInfo = $this->splitTextRecursive($s, $newSeparators);
            foreach ($otherInfo as $o) {
                $finalChunks[] = $o;
            }
        }
    }
}
```

Wait, there's a subtle issue here. The condition is `$this->lengthOf($s) < $this->chunkSize`. If a split is exactly `chunkSize`, it's treated as "too long" and either emitted directly or recursed into. But in `mergeSplits`, the condition for adding a split is:
```php
if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize) {
```

If `$total = 0`, `$len = chunkSize`, and `count($currentDoc) = 0`, then `0 + chunkSize + 0 > chunkSize` is `false`. So a single split of exactly `chunkSize` would be added to `$currentDoc` without triggering the merge. But in `splitTextRecursive`, it's treated as "too long" and not added to `$goodSplits`.

This means a split of exactly `chunkSize` is handled differently in `splitTextRecursive` vs `mergeSplits`. In `splitTextRecursive`, it's recursed into or emitted directly. In `mergeSplits`, it would be added to the current doc.

But this is actually correct behavior for the recursive splitter: if a split is exactly `chunkSize` or larger, it needs further splitting (if possible) rather than being merged with other splits. The `<` vs `<=` distinction ensures that splits at the chunk size boundary are properly handled.

Actually, wait. Let me think about this more carefully. If a split has length exactly `chunkSize`, and it's the only split, then:
- In `splitTextRecursive`: it's treated as "too long" (`< chunkSize` is false), so it's either recursed into or emitted directly
- If emitted directly, it becomes a chunk of exactly `chunkSize`

If it were treated as "good" and added to `$goodSplits`, then `mergeSplits` would produce a chunk of exactly `chunkSize`. The result is the same.

But if there are other splits, the behavior differs:
- As "too long": the good splits are flushed first, then the long split is recursed/emitted
- As "good": it would be merged with other good splits, potentially exceeding `chunkSize`

So the `<` condition prevents merging a `chunkSize`-length split with any other split, which would exceed the budget. This is correct.

**Let me now look at the `TextSplitter::mergeSplits` method for the `joinDocs` call:**

```php
protected static function joinDocs(array $docs, string $separator): ?string
{
    $text = trim(implode($separator, $docs));

    return $text === '' ? null : $text;
}
```

This joins the docs with the separator and trims the result. If the result is empty, it returns `null`. This is correct.

But wait, what if the separator is `''` (empty string)? Then `implode('', $docs)` just concatenates the docs. And `trim('')` returns `''`. So if all docs are empty strings, `joinDocs` returns `null`. But empty docs are filtered out by `splitOnSeparator`, so this shouldn't happen.

**Let me now look at the `TextSplitter::createDocuments` method for the `chunkHeader` and `chunkOverlapHeader`:**

```php
$pageContent = $options->chunkHeader;
```

And:
```php
if ($options->appendChunkOverlapHeader) {
    $pageContent .= $options->chunkOverlapHeader;
}
```

These are options for adding headers to chunks. The `$options->chunkHeader` is prepended to every chunk, and `$options->chunkOverlapHeader` is prepended to chunks after the first (to indicate overlap). This is a feature for adding metadata headers to chunks.

But wait, the `$options->chunkOverlapHeader` is added BEFORE the chunk content:
```php
$pageContent .= $options->chunkOverlapHeader;
}
$pageContent .= $chunk;
```

So the page content is: `chunkHeader` + (for non-first chunks) `chunkOverlapHeader` + `chunk`. This seems correct.

**Let me now look at the `TextSplitter::splitOnSeparator` method for the `keepSeparator` case with `preg_split`:**

```php
$splits = preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text);
```

The regex uses `preg_quote` to escape the separator. But `preg_quote` escapes characters that are regex metacharacters. For a separator like `"\n\n"`, `preg_quote` would escape nothing (since `\n` is not a regex metacharacter). Wait, actually, `preg_quote("\n\n")` would return `"\n\n"` since newlines are not regex metacharacters. But in the regex pattern, `\n` in a character class or lookahead would match a literal newline. So the pattern would be `/(?=\n\n)/u`, which matches positions before two consecutive newlines. This is correct.

But what about separators that contain regex metacharacters? E.g., `'<body>'`. `preg_quote('<body>')` returns `'<body>'` (since `<` and `>` are not regex metacharacters in PHP). Wait, actually, in PHP's `preg_quote`, the characters escaped are: `. \ + * ? [ ^ ] $ ( ) { } = ! < > | : - #`. Actually, let me check... `preg_quote` in PHP escapes: `. \ + * ? [ ^ ] $ ( ) { } = ! < > | : -`. No wait, the actual list is: `. \ + * ? [ ^ ] $ ( ) { } = ! < > | : - #`. But the `#` is only escaped if used as the delimiter.

Actually, the PHP docs say `preg_quote` escapes the following characters: `. \ + * ? [ ^ ] $ ( ) { } = ! < > | : -`. And the `-` is escaped only in certain contexts.

So `preg_quote('<body>')` would return `<body>` (since none of those characters need escaping in a lookahead context). Wait, actually `<` and `>` ARE in the list! Let me check again.

PHP's `preg_quote` escapes: `. \ + * ? [ ^ ] $ ( ) { } = ! < > | : -`

So `preg_quote('<body>')` returns `\<body\>`. And the regex pattern would be `/(?=\<body\>)/u`. This matches positions before `<body>`. Correct.

OK, the `preg_quote` usage is correct.

**Let me now look at the `RecursiveCharacterTextSplitter::getSeparatorsForLanguage` for the HTML case:**

```php
Language::HTML => [
    '<body>',
    '<div>',
    ...
    ' ',
    '',
],
```

These separators don't start with `\n`. They're raw HTML tags. With `keepSeparator` true, the lookahead split would keep the tag attached to the following piece. So a chunk might start with `<body>`, `<div>`, etc. This is the documented behavior.

But wait, the HTML separators include `' '` (space) and `''` (empty string) as the last two. This means for HTML text, the splitter would first try to split on `<body>`, then `<div>`, etc., then on spaces, then on individual characters. This seems reasonable.

**Let me now look at the `RecursiveCharacterTextSplitter::getSeparatorsForLanguage` for the MARKDOWN case:**

```php
Language::MARKDOWN => [
    "\n## ",
    "\n### ",
    "\n#### ",
    "\n##### ",
    "\n###### ",
    "```\n\n",
    "\n\n***\n\n",
    "\n\n---\n\n",
    "\n\n___\n\n",
    "\n\n",
    "\n",
    ' ',
    '',
],
```

The separator `"```\n\n"` is for code block endings. This is a multi-line separator. With `keepSeparator` true, the lookahead split would keep `"```\n\n"` attached to the following piece. This means a chunk after a code block would start with `"```\n\n"`. This might not be ideal (the code block fence would be at the start of the next chunk), but it matches upstream.

**Let me now look at the `RecursiveCharacterTextSplitter::getSeparatorsForLanguage` for the LATEX case:**

```php
Language::LATEX => [
    "\n\\chapter{",
    "\n\\section{",
    "\n\\subsection{",
    "\n\\subsubsection{",
    "\n\\begin{enumerate}",
    "\n\\begin{itemize}",
    "\n\\begin{description}",
    "\n\\begin{list}",
    "\n\\begin{quote}",
    "\n\\begin{quotation}",
    "\n\\begin{verse}",
    "\n\\begin{verbatim}",
    "\n\\begin{align}",
    '$$',
    '$',
    "\n\n",
    "\n",
    ' ',
    '',
],
```

The separators `'$$'` and `'$'` are for math environments. These don't start with `\n`. With `keepSeparator` true, the lookahead split would keep `$$` or `$` attached to the following piece. This seems correct for LaTeX math mode.

**Let me now look at the `RecursiveCharacterTextSplitter::getSeparatorsForLanguage` for the SOL case:**

```php
Language::SOL => [
    "\npragma ",
    "\nusing ",
    "\ncontract ",
    "\ninterface ",
    "\nlibrary ",
    "\nconstructor ",
    "\ntype ",
    "\nfunction ",
    "\nevent ",
    "\nmodifier ",
    "\nerror ",
    "\nstruct ",
    "\nenum ",
    "\nif ",
    "\nfor ",
    "\nwhile ",
    "\ndo while ",
    "\nassembly ",
    "\n\n",
    "\n",
    ' ',
    '',
],
```

This is for Solidity. The separators look correct.

**Now let me think about what the five most impactful findings are, given the code I've reviewed:**

1. **`splitOnSeparator` crash on empty separator with malformed UTF-8** - This is a concrete crash bug. When the separator is `''` (character-level splitting) and the text has malformed UTF-8, `mb_str_split` returns `false`, and the fallback `explode('', $text)` throws `ValueError` in PHP 8.

2. **`splitOnSeparator` fallback silently drops `keepSeparator`** - When `keepSeparator` is true and `preg_split` fails on malformed UTF-8, the fallback `explode` doesn't keep separators attached. This is a silent behavior change.

3. **`RecursiveCharacterTextSplitter::__construct` double-sets `keepSeparator`** - The child constructor redundantly sets `keepSeparator` after the parent constructor already set it. While the net result is correct, it's fragile and confusing.

4. **`TextSplitter::mergeSplits` - separator count uses `count($currentDoc)` which overcounts by 1** - Wait, I already checked this and it's correct. Let me re-examine...

Actually, let me re-examine this one more time. The formula is:
```
total + length + count($currentDoc) * separator_length
```

Where `count($currentDoc)` is the number of pieces currently in `$currentDoc`. After adding the new piece, we'd have `count($currentDoc) + 1` pieces, needing `count($currentDoc)` separators. So the formula is correct.

But wait, what about the INITIAL state? When `$currentDoc` is empty (`count($currentDoc) = 0`), the formula becomes `total + length + 0 * separator_length = total + length`. Since `total = 0` initially, this is just `length`. So a single piece of length `chunkSize` would pass the check (`length > chunkSize` is false for `length = chunkSize`). This is correct.

When `$currentDoc` has 1 piece, `count($currentDoc) = 1`. The formula is `total + length + 1 * separator_length`. After adding the new piece, we'd have 2 pieces needing 1 separator. So the formula checks `total + length + separator_length > chunkSize`. This is correct.

OK, the formula is correct. Let me move on.

5. **`RecursiveCharacterTextSplitter::splitTextRecursive` - `$newSeparators` is not set when `''` is the separator** - When the empty string is found as the separator, `$newSeparators` remains `null`. This means over-budget fragments are emitted directly without further recursion. This is correct behavior since there are no more separators to try. But it also means the oversized chunk warning is never emitted for these fragments.

Actually, let me reconsider. The oversized chunk warning is emitted in `mergeSplits`, not in `splitTextRecursive`. In `splitTextRecursive`, over-budget fragments are either recursed into (if there are more separators) or emitted directly (if there are no more separators). They never go through `mergeSplits`. So the warning system is only relevant for fragments that go through `mergeSplits`.

But wait, `mergeSplits` is called on `$goodSplits` which contains splits that are `< chunkSize`. So the warning would only fire if multiple good splits together exceed `chunkSize`. This can happen if the separator is very short (e.g., `''`) and many single characters are merged. In that case, the warning would fire.

But for a single split that's `>= chunkSize`, it's either recursed into or emitted directly. If emitted directly, no warning is emitted. This means a chunk that's over budget due to a single large split doesn't trigger the warning. But this matches upstream behavior (upstream also doesn't warn in this case).

OK, let me now focus on finding the five most impactful findings.

Actually, let me re-read the code one more time to see if I missed anything.

**`TextSplitter::splitOnSeparator` - the `keepSeparator` + `preg_split` case:**

```php
if ($this->keepSeparator) {
    $splits = preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text);
} else {
    $splits = explode($separator, $text);
}
```

Wait, I just noticed something. When `keepSeparator` is true, the regex uses a lookahead to split BEFORE each separator. This means the separator stays attached to the FOLLOWING piece. But what about the LAST piece? If the text ends with the separator, the last piece would be empty (since the lookahead matches at the end of the text too). Actually, no - `preg_split` with a lookahead doesn't match at the end unless the lookahead is satisfied. The lookahead `(?=separator)` matches positions where the separator follows. If the text ends with the separator, the position before the separator matches, but there's no position after the last character that would produce an empty trailing piece.

Wait, let me think about this more carefully. `preg_split` splits the string at each position where the pattern matches. The pattern `(?=separator)` matches positions where the separator starts. So for `text = "abcseparatordefseparatorghi"` and `separator = "separator"`, the pattern matches at positions 3 and 13. `preg_split` would produce `["abc", "separatordef", "separatorghi"]`. The separators are attached to the following pieces. This is correct.

But what if the text starts with the separator? E.g., `text = "separatorabc"` and `separator = "separator"`. The pattern matches at position 0. `preg_split` would produce `["", "separatorabc"]`. After filtering, this becomes `["separatorabc"]`. The empty first piece is removed. This is correct.

What if the text is just the separator? E.g., `text = "separator"` and `separator = "separator"`. The pattern matches at position 0. `preg_split` would produce `["", ""]`. Wait, does it? Let me think... The pattern matches at position 0 (before the separator). `preg_split` splits at this position, producing `["", "separator"]`. But then the limit is reached... actually, `preg_split` by default splits at ALL matches. So it would produce `["", "separator"]`. Wait, no. The pattern `(?=separator)` matches at position 0 (before "separator"). It also matches at position 9 (after "separator"), because... no, it doesn't. The lookahead `(?=separator)` requires the separator to follow. At position 9 (end of string), there's nothing following, so the lookahead doesn't match. So `preg_split` produces `["", "separator"]`. After filtering, this becomes `["separator"]`. So a text that IS the separator produces one piece: the separator itself. This is correct.

What if the text is `"separatorseparator"`? The pattern matches at positions 0 and 9. `preg_split` produces `["", "separator", "separator"]`. Wait, no. Let me think again. The pattern matches at position 0 (before the first "separator") and position 9 (before the second "separator"). `preg_split` splits at these positions, producing `["", "separator", "separator"]`. After filtering, this becomes `["separator", "separator"]`. So two pieces, each starting with the separator. This is correct.

OK, the `keepSeparator` + `preg_split` logic is correct.

**Let me now look at the `TextSplitter::mergeSplits` method for the case where `$currentDoc` is empty:**

```php
if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize) {
    if ($total > $this->chunkSize) {
        $this->warnOversizedChunk($total);
    }

    if ($currentDoc !== []) {
        $doc = self::joinDocs($currentDoc, $separator);
        if ($doc !== null) {
            $docs[] = $doc;
        }

        while (...) {
            $total -= $this->lengthOf($currentDoc[0]);
            array_shift($currentDoc);
        }
    }
}
```

When `$currentDoc` is empty (`count($currentDoc) = 0`), the condition becomes `$total + $len > $this->chunkSize`. Since `$total = 0`, this is `$len > $this->chunkSize`. If a single split is larger than `chunkSize`, this condition is true. But `$currentDoc !== []` is false, so the inner block is skipped. The split is then added to `$currentDoc` and `$total` becomes `$len`. On the next iteration, if there's another split, the condition would be `$len + $nextLen > $this->chunkSize`, which might be true. Then `$currentDoc !== []` is true, so the current doc (containing the oversized split) is emitted. This is correct.

But wait, the oversized chunk warning `if ($total > $this->chunkSize)` is inside the `if ($currentDoc !== [])` block? No, let me re-read:

```php
if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize) {
    if ($total > $this->chunkSize) {
        $this->warnOversizedChunk($total);
    }

    if ($currentDoc !== []) {
        ...
    }
}
```

No, the `if ($total > $this->chunkSize)` is NOT inside `if ($currentDoc !== [])`. It's at the same level. So the warning can fire even when `$currentDoc` is empty. But if `$currentDoc` is empty, `$total` is 0, and `0 > $this->chunkSize` is false (since `chunkSize` is positive). So the warning doesn't fire for the first oversized split. It would fire on a subsequent iteration when `$total` (which now contains the oversized split) is > `chunkSize`.

Wait, let me trace through an example. Say `chunkSize = 100`, and splits are `[150, 50]`.

Iteration 1: `$d = 150`, `$len = 150`
- Condition: `0 + 150 + 0 * sep > 100` → `true`
- Warning: `0 > 100` → `false` (no warning)
- `$currentDoc !== []` → `false` (skip)
- Add to currentDoc: `$currentDoc = [150]`, `$total = 150`

Iteration 2: `$d = 50`, `$len = 50`
- Condition: `150 + 50 + 1 * sep > 100` → `true`
- Warning: `150 > 100` → `true` (WARNING: "Created a chunk of size 150, which is longer than the specified 100")
- `$currentDoc !== []` → `true`
- `joinDocs([150], sep)` → emits chunk of size 150
- While loop: `150 > overlap` → pop 150, `$total = 0`, `$currentDoc = []`
  - Check: `0 > overlap` → depends on overlap
  - Check: `0 + 50 + 0 * sep > 100 && 0 > 0` → `false`
  - Loop ends
- Add to currentDoc: `$currentDoc = [50]`, `$total = 50`

End: `joinDocs([50], sep)` → emits chunk of size 50

So the warning fires on the SECOND iteration, when the oversized split is already in `$currentDoc`. The warning says "Created a chunk of size 150" which is the size of the chunk that was just emitted. This is correct!

But wait, the warning fires AFTER the chunk is emitted (since `joinDocs` is called before the while loop). Actually, no - the warning fires BEFORE `joinDocs`. Let me re-read:

```php
if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize) {
    if ($total > $this->chunkSize) {
        $this->warnOversizedChunk($total);
    }

    if ($currentDoc !== []) {
        $doc = self::joinDocs($currentDoc, $separator);
        if ($doc !== null) {
            $docs[] = $doc;
        }
        ...
    }
}
```

So the warning fires before the chunk is emitted. The warning says "Created a chunk of size 150" even though the chunk hasn't been created yet. The wording is a bit off (it should say "about to create" or "would create"), but this matches upstream.

OK, I think I've been overthinking this. Let me now focus on the five findings.

Actually, let me look at one more thing. The `TextSplitter::createDocuments` method:

```php
foreach ($texts as $i => $text) {
    $lineCounterIndex = 1;
    $prevChunk = null;
    $indexPrevChunk = -1;

    foreach ($this->splitText($text) as $chunk) {
```

Wait, `$indexPrevChunk = -1` is initialized to `-1`. Then:
```php
$indexChunk = self::indexOf($text, $chunk, $indexPrevChunk + 1);
```

For the first chunk, `$indexPrevChunk + 1 = 0`. So `indexOf($text, $chunk, 0)` searches from the beginning. This is correct.

But what if `$indexChunk` is `-1` (chunk not found)? Then:
```php
if ($prevChunk === null) {
    $newLinesBeforeFirstChunk = self::numberOfNewLines($text, 0, $indexChunk);
```

`numberOfNewLines($text, 0, -1)` calls `slice($text, 0, -1)` which returns the text minus the last character. Then `substr_count($textSection, "\n")` counts newlines. This gives a slightly wrong line count, but it's an edge case.

For subsequent chunks:
```php
} else {
    $indexEndPrevChunk = $indexPrevChunk + mb_strlen($prevChunk, 'UTF-8');
    if ($indexEndPrevChunk < $indexChunk) {
        $numberOfIntermediateNewLines = self::numberOfNewLines(
            $text,
            $indexEndPrevChunk,
            $indexChunk,
        );
        $lineCounterIndex += $numberOfIntermediateNewLines;
    } elseif ($indexEndPrevChunk > $indexChunk) {
        $numberOfIntermediateNewLines = self::numberOfNewLines(
            $text,
            $indexChunk,
            $indexEndPrevChunk,
        );
        $lineCounterIndex -= $numberOfIntermediateNewLines;
    }
}
```

If `$indexPrevChunk` is `-1` (previous chunk not found), then `$indexEndPrevChunk = -1 + mb_strlen($prevChunk, 'UTF-8')`. If the previous chunk has length 10, `$indexEndPrevChunk = 9`. Then if `$indexChunk` is also `-1` (current chunk not found), `$indexEndPrevChunk < $indexChunk` is `9 < -1` which is `false`. And `$indexEndPrevChunk > $indexChunk` is `9 > -1` which is `true`. So `numberOfNewLines($text, -1, 9)` is called. `slice($text, -1, 9)` would... let me check:

```php
if ($start < 0) {
    $start = max(0, $length + $start);
}
```

With `$start = -1` and `$length` being the text length, `$start = max(0, $length - 1)`. And `$end = 9`. If `$length` is, say, 100, then `$start = 99` and `$end = 9`. Since `$end <= $start`, `slice` returns `''`. So `numberOfNewLines` returns 0. The line counter doesn't change. This is a bit odd but it's an edge case where chunks can't be found.

OK, I think I've been thorough enough. Let me now compile my five findings.

Actually, wait. Let me re-read the `splitOnSeparator` method one more time:

```php
protected function splitOnSeparator(string $text, string $separator): array
{
    if ($separator !== '') {
        if ($this->keepSeparator) {
            $splits = preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text);
        } else {
            $splits = explode($separator, $text);
        }
    } else {
        $splits = $text === '' ? [] : mb_str_split($text, 1, 'UTF-8');
    }

    if ($splits === false) {
        $splits = explode($separator, $text);
    }

    return array_values(array_filter($splits, static fn (string $s): bool => $s !== ''));
}
```

I notice that when `$separator !== ''` and `keepSeparator` is true, `preg_split` is used. If `preg_split` returns false (malformed UTF-8), the fallback is `explode($separator, $text)`. But `explode` doesn't keep the separator. So `keepSeparator` is silently violated.

And when `$separator === ''`, `mb_str_split` is used. If it returns false (malformed UTF-8), the fallback is `explode('', $text)` which throws `ValueError`.

These are two real bugs in the same method.

Let me also check: can `explode` return false? In PHP 8.x, `explode` never returns false. It either returns an array or throws `ValueError`. So the `if ($splits === false)` check only catches `preg_split` and `mb_str_split` failures.

Actually, wait. In PHP 8.x, `explode` with an empty separator throws `ValueError`. So the fallback `explode($separator, $text)` when `$separator` is `''` would throw. This is the crash bug.

And `explode` with a non-empty separator always returns an array (never false). So the fallback works for non-empty separators.

OK, let me now write up my five findings.

**Finding 1: `splitOnSeparator` crashes on empty separator with malformed UTF-8**

When the separator is `''` (character-level splitting, the floor of every language hierarchy) and the text contains malformed UTF-8, `mb_str_split` returns `false`, and the fallback `explode('',