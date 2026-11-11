<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

/**
 * A recursive descent JSON parser that accepts truncated documents.
 *
 * Port of `strictParsePartialJson` from `@langchain/core/utils/json`.
 *
 * Open containers and truncated strings, literals, and numbers recover the
 * value represented by the prefix, following the upstream streaming parser.
 *
 * PHP reports offsets in Unicode code points; the TypeScript original reports
 * UTF-16 code-unit offsets. This is a deliberate diagnostic adaptation.
 *
 * @internal
 */
final class PartialJsonParser
{
    /** Exact UTF-8 bytes for ECMAScript WhiteSpace and LineTerminator, without a /u failure path. */
    private const WHITESPACE = '(?:[\\x09-\\x0D\\x20]|\\xC2\\xA0|\\xE1\\x9A\\x80|\\xE2\\x80[\\x80-\\x8A\\xA8\\xA9\\xAF]|\\xE2\\x81\\x9F|\\xE3\\x80\\x80|\\xEF\\xBB\\xBF)';

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

    /** @var list<string> One code-point token per index, including substituted malformed byte sequences. */
    private readonly array $characters;

    public function __construct(string $buffer)
    {
        $this->characters = self::splitCharacters(self::trimWhitespace($buffer));
        $this->length = count($this->characters);
    }

    /** Shared String.trim() adaptation for both the markdown boundary and partial fallback. */
    public static function trimWhitespace(string $buffer): string
    {
        return preg_replace('/\A' . self::WHITESPACE . '+|' . self::WHITESPACE . '+\z/', '', $buffer) ?? $buffer;
    }

    /**
     * Tokenize once, making subsequent indexed character reads constant-time.
     *
     * mb_str_split is linear for valid UTF-8. Its malformed-input behavior differs
     * from mb_substr, so invalid sequences are consumed as UTF-8 maximal subparts
     * and converted individually, retaining the existing mbstring substitution
     * policy and one diagnostic position per malformed subpart.
     *
     * @return list<string>
     */
    private static function splitCharacters(string $buffer): array
    {
        if (mb_check_encoding($buffer, 'UTF-8')) {
            return mb_str_split($buffer, 1, 'UTF-8');
        }

        $characters = [];
        $bytes = strlen($buffer);
        for ($offset = 0; $offset < $bytes;) {
            $lead = ord($buffer[$offset]);
            $width = match (true) {
                $lead < 0x80 => 1,
                $lead >= 0xC2 && $lead <= 0xDF => 2,
                $lead >= 0xE0 && $lead <= 0xEF => 3,
                $lead >= 0xF0 && $lead <= 0xF4 => 4,
                default => 0,
            };
            $consumed = 1;
            while ($consumed < $width && $offset + $consumed < $bytes) {
                $next = ord($buffer[$offset + $consumed]);
                if ($next < 0x80 || $next > 0xBF
                    || ($consumed === 1 && (($lead === 0xE0 && $next < 0xA0)
                        || ($lead === 0xED && $next > 0x9F)
                        || ($lead === 0xF0 && $next < 0x90)
                        || ($lead === 0xF4 && $next > 0x8F)))) {
                    break;
                }
                $consumed++;
            }
            $part = substr($buffer, $offset, $consumed);
            $characters[] = $width > 0 && $consumed === $width
                ? $part
                : mb_convert_encoding($part, 'UTF-8', 'UTF-8');
            $offset += $consumed;
        }

        return $characters;
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
        return $this->characters[$i] ?? '';
    }

    /** Bounded lookahead (at most five code points), with no scan from the buffer's beginning. */
    private function substring(int $start, int $length): string
    {
        $result = '';
        $end = min($start + $length, $this->length);
        for ($i = $start; $i < $end; $i++) {
            $result .= $this->characters[$i];
        }

        return $result;
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->length && preg_match('/\A' . self::WHITESPACE . '\z/', $this->charAt($this->pos)) === 1) {
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

        // Upstream checks whether each literal starts with the available
        // prefix. Its token-sized lookahead also rejects malformed prefixes
        // followed by a delimiter, such as `[n,` or `{"a": tr}`.
        if (str_starts_with('null', $this->substring($this->pos, 4))) {
            $this->pos += min(4, $this->length - $this->pos);

            return null;
        }
        if (str_starts_with('true', $this->substring($this->pos, 4))) {
            $this->pos += min(4, $this->length - $this->pos);

            return true;
        }
        if (str_starts_with('false', $this->substring($this->pos, 5))) {
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
                if ($char !== 'u') {
                    $result .= $this->flushUnpairedHighSurrogate();
                }
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
                $result .= $this->flushUnpairedHighSurrogate() . $char;
            }

            $this->pos++;
        }

        if ($escaped) {
            $result .= $this->flushUnpairedHighSurrogate() . '\\';
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
        // U+FFFD is the same choice already made for an unpaired LOW surrogate:
        // something has to occupy
        // the position, and the replacement character says "there was a
        // character here and I could not read it" rather than pretending the
        // input never mentioned it.
        if ($this->pendingHighSurrogate !== null) {
            $this->pendingHighSurrogate = null;
            $result .= "\u{FFFD}";
        }

        return $result;
    }

    private function flushUnpairedHighSurrogate(): string
    {
        if ($this->pendingHighSurrogate === null) {
            return '';
        }

        $this->pendingHighSurrogate = null;

        return "\u{FFFD}";
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
        $hex = $this->substring($this->pos + 1, 4);
        $hexLength = mb_strlen($hex, 'UTF-8');

        if (preg_match('/^[0-9A-Fa-f]{0,4}$/', $hex) === 1) {
            // Advance in BOTH branches, as upstream does (`pos += hex.length`
            // sits outside the length check, json.ts:89). Advancing only in the
            // four-digit branch left the partial case re-reading the hex digits
            // as ordinary characters, so a fragment ending mid-escape produced
            // `au1212` where upstream produces `au12`.
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
                    $prefix = $this->flushUnpairedHighSurrogate();
                    $this->pendingHighSurrogate = $code;

                    return $prefix;
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

            // Upstream recovers short unicode escapes as `u${hex}`. A lone
            // backslash is handled separately by parseString().
            return $this->flushUnpairedHighSurrogate() . 'u' . $hex;
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

        if ($numStr === '-') {
            return -0.0;
        }

        // Number.parseFloat rejects prefixes with no significand digits,
        // including `-.` and `-e1`. PHP casts these strings to zero instead.
        // A digit after the decimal point is sufficient (`-.5`), and an
        // incomplete exponent after a valid significand still recovers it.
        if (preg_match('/^-?(?:[0-9]+|\.[0-9]+)/', $numStr) !== 1) {
            $this->pos = $start;
            throw new \RuntimeException("Invalid number '{$numStr}' at position {$start}");
        }

        // Number.parseFloat uses IEEE-754 doubles for every number. Casting
        // oversized integer strings directly to int would saturate at PHP's
        // integer bounds instead of rounding or overflowing like JavaScript.
        $number = (float) $numStr;
        if ($number == 0.0 && str_starts_with($numStr, '-')) {
            return -0.0;
        }

        // Retain the port's int representation only for safely representable
        // integer tokens. Outside JS's safe integer range, return the double.
        if (!str_contains($numStr, '.') && !str_contains($numStr, 'e') && !str_contains($numStr, 'E')
            && abs($number) <= min(PHP_INT_MAX, 9007199254740991)) {
            return (int) $number;
        }

        return $number;
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
