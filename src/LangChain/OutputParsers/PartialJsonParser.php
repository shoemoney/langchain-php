<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

/**
 * A recursive descent JSON parser that accepts truncated documents.
 *
 * Port of `strictParsePartialJson` from `@langchain/core/utils/json`.
 *
 * It differs from `json_decode` in exactly one way: hitting the end of the
 * buffer while a container is still open returns the container as it stands
 * rather than failing. That is what lets a streamed `{` become `[]` and a
 * streamed `{"a": 1` become `['a' => 1]` on the way to the finished value.
 *
 * Offsets are counted in code points to match the TypeScript original, which
 * indexes by UTF-16 unit.
 *
 * @internal
 */
final class PartialJsonParser
{
    private int $pos = 0;

    /**
     * A high surrogate awaiting its low partner.
     *
     * JSON writes every astral character as a UTF-16 surrogate PAIR, so this is
     * set on the first half and consumed on the second. See
     * {@see self::parseUnicodeEscape()}.
     */
    private ?int $pendingHighSurrogate = null;

    private readonly int $length;

    public function __construct(private readonly string $buffer)
    {
        $this->length = mb_strlen($buffer, 'UTF-8');
    }

    /**
     * @throws \RuntimeException on malformed input
     */
    public function parse(): mixed
    {
        $value = $this->parseValue();
        $this->skipWhitespace();

        if ($this->pos < $this->length) {
            throw new \RuntimeException(
                "Unexpected character '{$this->charAt($this->pos)}' at position {$this->pos}"
            );
        }

        return $value;
    }

    private function charAt(int $i): string
    {
        return mb_substr($this->buffer, $i, 1, 'UTF-8');
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->length && ctype_space($this->charAt($this->pos))) {
            $this->pos++;
        }
    }

    private function parseValue(): mixed
    {
        $this->skipWhitespace();

        if ($this->pos >= $this->length) {
            throw new \RuntimeException("Unexpected end of input at position {$this->pos}");
        }

        $char = $this->charAt($this->pos);
        if ($char === '{') {
            return $this->parseObject();
        }
        if ($char === '[') {
            return $this->parseArray();
        }
        if ($char === '"') {
            return $this->parseString();
        }

        // Argument order matters and it was backwards: the PHP signature is
        // str_starts_with($haystack, $needle), so this used to ask "does the
        // literal 'null' start with the rest of the buffer?" — never true. Every
        // literal therefore fell through to the error path, which meant COMPLETE
        // and perfectly valid JSON containing `true`, `false` or `null` threw
        // `Unexpected character`. This is not about partial input at all.
        $rest = mb_substr($this->buffer, $this->pos, 5, 'UTF-8');
        if (str_starts_with($rest, 'null')) {
            $this->pos += min(4, $this->length - $this->pos);

            return null;
        }
        if (str_starts_with($rest, 'true')) {
            $this->pos += min(4, $this->length - $this->pos);

            return true;
        }
        if (str_starts_with($rest, 'false')) {
            $this->pos += min(5, $this->length - $this->pos);

            return false;
        }
        if ($char === '-' || ($char >= '0' && $char <= '9')) {
            return $this->parseNumber();
        }

        throw new \RuntimeException("Unexpected character '{$char}' at position {$this->pos}");
    }

    private function parseString(): string
    {
        if ($this->charAt($this->pos) !== '"') {
            throw new \RuntimeException("Expected '\"' at position {$this->pos}");
        }

        $this->pos++;
        $result = '';
        $escaped = false;

        while ($this->pos < $this->length) {
            $char = $this->charAt($this->pos);

            if ($escaped) {
                $result .= match ($char) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '\\' => '\\',
                    '"' => '"',
                    'b' => "\x08",
                    'f' => "\f",
                    '/' => '/',
                    'u' => $this->parseUnicodeEscape(),
                    default => throw new \RuntimeException(
                        "Invalid escape sequence '\\{$char}' at position {$this->pos}"
                    ),
                };
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $this->pos++;

                // A high surrogate still pending at the closing quote never
                // found its partner, and returning here used to discard it
                // SILENTLY. Measured: {"a":"x\ud800y"} returned {"a":"xy"} — three
                // characters became two and nothing reported it, which is the
                // worst possible failure for a parser: lossy and quiet.
                //
                // Upstream does not hit this because `String.fromCharCode` yields
                // the lone surrogate and a JS string holds code units natively.
                // PHP has no such value — mbstring refuses it, since a surrogate
                // is not a Unicode scalar — so U+FFFD is the faithful stand-in,
                // and it is already this method's documented policy for an
                // unpaired surrogate. The only gap was this path.
                if ($this->pendingHighSurrogate !== null) {
                    $this->pendingHighSurrogate = null;

                    return $result . "\u{FFFD}";
                }

                return $result;
            } else {
                $result .= $char;
            }

            $this->pos++;
        }

        if ($escaped) {
            $result .= '\\';
        }

        // A stream cut INSIDE an escape pair must not lose the half it saw.
        //
        // `parseUnicodeEscape()` stores a high surrogate and returns '' while it
        // waits for its low partner. If the stream ends there, the state was
        // simply abandoned and the character vanished.
        //
        // Measured before this: `"a\uD83D` recovered as bytes `61` — the letter
        // 'a' and nothing else, the high surrogate dropped with no error. A
        // consumer diffing partial results would see the string lose a
        // character silently, which is worse than seeing U+FFFD where the
        // character began.
        //
        // U+FFFD is the same choice already made for an unpaired LOW surrogate,
        // and for the short-hex truncation at line 223: something has to occupy
        // the position, and the replacement character says "there was a
        // character here and I could not read it" rather than pretending the
        // input never mentioned it.
        if ($this->pendingHighSurrogate !== null) {
            $this->pendingHighSurrogate = null;
            $result .= "\u{FFFD}";
        }

        return $result;
    }

    /**
     * Encode a code point, falling back to U+FFFD only when mbstring REFUSES it.
     *
     * The obvious `mb_chr($code, 'UTF-8') ?: "\u{FFFD}"` is wrong, and wrong in
     * the most PHP way possible: `"0"` is FALSY. So `mb_chr(0x30)` returns the
     * one-character string `"0"`, the `?:` reads that as failure, and every
     * escape for a literal zero — plus `\u0000`, NUL, which is equally falsy in
     * spirit — decoded to U+FFFD. Measured before the fix:
     *
     *     {"a": "x\u0030y"}  ->  {"a":"x\ufffdy"}
     *
     * The same shape guarded the astral reassembly, so a supplementary-plane
     * character whose encoding was falsy would have been replaced too. mb_chr
     * returns `string|false`, so the only correct test is `=== false`.
     */
    private static function encodeCodePoint(int $code): string
    {
        $char = mb_chr($code, 'UTF-8');

        return $char === false ? "\u{FFFD}" : $char;
    }

    private function parseUnicodeEscape(): string
    {
        $hex = mb_substr($this->buffer, $this->pos + 1, 4, 'UTF-8');
        $hexLength = mb_strlen($hex, 'UTF-8');

        if (preg_match('/^[0-9A-Fa-f]{0,4}$/', $hex) === 1) {
            // Advance in BOTH branches, as upstream does (`pos += hex.length`
            // sits outside the length check, json.ts:89). Advancing only in the
            // four-digit branch left the partial case re-reading the hex digits
            // as ordinary characters, so a fragment ending mid-escape produced
            // `au1212` where upstream produces `au122`.
            $this->pos += $hexLength;

            if ($hexLength === 4) {
                $code = (int) hexdec($hex);

                // Surrogate pairs: JSON encodes every ASTRAL character — every
                // emoji, every CJK extension ideograph, every historic script —
                // as two UTF-16 code units. So a single emoji arrives here as
                // two escapes, high half then low half.
                //
                // Upstream does not deal with this, because it does not have
                // to: `String.fromCharCode(parseInt(hex, 16))` (json.ts:86)
                // returns the lone surrogate 0xD83D, JS strings hold surrogate
                // code units natively, and the two halves together render as
                // the emoji.
                //
                // PHP's mbstring is stricter and CORRECT: a surrogate is not a
                // Unicode scalar value, so `mb_chr(0xD83D, 'UTF-8')` returns
                // false — and this method is typed `string`, so a stream
                // containing ANY emoji died with
                //   TypeError: parseUnicodeEscape(): Return value must be of
                //   type string, false returned
                // measured on `new PartialJsonParser('"😀"')`.
                //
                // So the pair is reassembled into the scalar it stands for,
                // which mbstring CAN encode and which is the character the
                // caller asked for. A lone surrogate that never finds its
                // partner becomes U+FFFD rather than fataling, because a stream
                // cut mid-pair is the normal case here, not an error.
                if ($code >= 0xD800 && $code <= 0xDBFF) {
                    $this->pendingHighSurrogate = $code;

                    return '';
                }

                if ($code >= 0xDC00 && $code <= 0xDFFF) {
                    $high = $this->pendingHighSurrogate;
                    $this->pendingHighSurrogate = null;

                    if ($high === null) {
                        return "\u{FFFD}";
                    }

                    $scalar = 0x10000 + (($high - 0xD800) << 10) + ($code - 0xDC00);

                    return self::encodeCodePoint($scalar);
                }

                // A plain BMP escape. Anything left pending was a high surrogate
                // that never got a partner.
                if ($this->pendingHighSurrogate !== null) {
                    $this->pendingHighSurrogate = null;

                    return "\u{FFFD}" . self::encodeCodePoint($code);
                }

                return self::encodeCodePoint($code);
            }

            // Fewer than four digits: emit the raw text WITH ITS BACKSLASH.
            //
            // `parseString` already has a rule for a stream cut mid-escape — it
            // re-emits the backslash (`if ($escaped) { $result .= '\\'; }`) so
            // the recovered text looks like what the caller wrote. This branch
            // broke that rule: `match` had already consumed the `\\`, so
            // returning `'u' . $hex` dropped it.
            //
            // Measured before the fix, by bytes: `"a\u1` recovered as
            // 61 75 31 — 'a', 'u', '1', with no 5c. So a caller diffing partial
            // results — `JsonOutputParser`'s `diff: true` path — sees the string
            // flip from '' to 'au1' to 'a\u1234' to the real character, and emits
            // a spurious JSON-Patch `replace` for every chunk of every emoji in
            // the stream. Silent, and it grows with the payload.
            //
            // Keeping the escape is also the more faithful reading: the input
            // contained a backslash, and dropping it invents a different string.
            return '\\u' . $hex;
        }

        throw new \RuntimeException(
            "Invalid unicode escape sequence '\\u{$hex}' at position {$this->pos}"
        );
    }

    private function parseNumber(): int|float
    {
        $start = $this->pos;
        $numStr = '';

        if ($this->charAt($this->pos) === '-') {
            $numStr .= '-';
            $this->pos++;
        }

        if ($this->pos < $this->length && $this->charAt($this->pos) === '0') {
            $numStr .= '0';
            $this->pos++;

            $next = $this->pos < $this->length ? $this->charAt($this->pos) : '';
            if ($next >= '0' && $next <= '9') {
                throw new \RuntimeException("Invalid number at position {$start}");
            }
        }

        if ($this->pos < $this->length && $this->charAt($this->pos) >= '1' && $this->charAt($this->pos) <= '9') {
            while ($this->pos < $this->length && $this->charAt($this->pos) >= '0' && $this->charAt($this->pos) <= '9') {
                $numStr .= $this->charAt($this->pos);
                $this->pos++;
            }
        }

        if ($this->pos < $this->length && $this->charAt($this->pos) === '.') {
            $numStr .= '.';
            $this->pos++;
            while ($this->pos < $this->length && $this->charAt($this->pos) >= '0' && $this->charAt($this->pos) <= '9') {
                $numStr .= $this->charAt($this->pos);
                $this->pos++;
            }
        }

        if ($this->pos < $this->length && ($this->charAt($this->pos) === 'e' || $this->charAt($this->pos) === 'E')) {
            $numStr .= $this->charAt($this->pos);
            $this->pos++;
            if ($this->pos < $this->length && ($this->charAt($this->pos) === '+' || $this->charAt($this->pos) === '-')) {
                $numStr .= $this->charAt($this->pos);
                $this->pos++;
            }
            while ($this->pos < $this->length && $this->charAt($this->pos) >= '0' && $this->charAt($this->pos) <= '9') {
                $numStr .= $this->charAt($this->pos);
                $this->pos++;
            }
        }

        if ($numStr === '' || $numStr === '-' || $numStr === '.') {
            $this->pos = $start;
            throw new \RuntimeException("Invalid number '{$numStr}' at position {$start}");
        }

        if (str_contains($numStr, '.') || str_contains($numStr, 'e') || str_contains($numStr, 'E')) {
            return (float) $numStr;
        }

        return (int) $numStr;
    }

    /** @return list<mixed> */
    private function parseArray(): array
    {
        $arr = [];
        $this->pos++;
        $this->skipWhitespace();

        if ($this->pos >= $this->length) {
            return $arr;
        }
        if ($this->charAt($this->pos) === ']') {
            $this->pos++;

            return $arr;
        }

        while ($this->pos < $this->length) {
            $this->skipWhitespace();
            if ($this->pos >= $this->length) {
                return $arr;
            }

            $arr[] = $this->parseValue();

            $this->skipWhitespace();
            if ($this->pos >= $this->length) {
                return $arr;
            }

            $char = $this->charAt($this->pos);
            if ($char === ']') {
                $this->pos++;

                return $arr;
            }
            if ($char === ',') {
                $this->pos++;
                continue;
            }

            throw new \RuntimeException("Expected ',' or ']' at position {$this->pos}, got '{$char}'");
        }

        return $arr;
    }

    /** @return array<string, mixed> */
    private function parseObject(): array
    {
        $obj = [];
        $this->pos++;
        $this->skipWhitespace();

        if ($this->pos >= $this->length) {
            return $obj;
        }
        if ($this->charAt($this->pos) === '}') {
            $this->pos++;

            return $obj;
        }

        while ($this->pos < $this->length) {
            $this->skipWhitespace();
            if ($this->pos >= $this->length) {
                return $obj;
            }

            $key = $this->parseString();

            $this->skipWhitespace();
            if ($this->pos >= $this->length) {
                return $obj;
            }

            if ($this->charAt($this->pos) !== ':') {
                throw new \RuntimeException("Expected ':' at position {$this->pos}");
            }
            $this->pos++;

            $this->skipWhitespace();
            if ($this->pos >= $this->length) {
                return $obj;
            }

            $obj[$key] = $this->parseValue();

            $this->skipWhitespace();
            if ($this->pos >= $this->length) {
                return $obj;
            }

            $char = $this->charAt($this->pos);
            if ($char === '}') {
                $this->pos++;

                return $obj;
            }
            if ($char === ',') {
                $this->pos++;
                continue;
            }

            throw new \RuntimeException("Expected ',' or '}' at position {$this->pos}, got '{$char}'");
        }

        return $obj;
    }
}
