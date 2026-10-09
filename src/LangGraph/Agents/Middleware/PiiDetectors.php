<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

/**
 * The detectors and strategies of `langchain/src/agents/middleware/pii.ts`.
 *
 * A match is `['text' => string, 'start' => int, 'end' => int]`. Offsets are BYTE offsets into the content (PHP
 * strings are byte strings; upstream counts UTF-16 code units), and every strategy slices by the same offsets,
 * so a detector's matches and the replacement agree. A custom callable detector receives the content and must
 * return offsets in bytes too.
 *
 * The patterns are upstream's re-expressed for PCRE without the `u` flag: that keeps `\b`, `\d` and `\s`
 * ASCII-only, exactly as they are in a JavaScript regex without `u`.
 *
 * A custom detector (`resolveRedactionRule`'s `detector`) is one of:
 *  - a callable `fn(string $content): list<match>`;
 *  - a string holding a complete delimited PCRE such as `'/\d+/'` (upstream's `RegExp`; the delimiter is one of
 *    `/ ~ # % @ !`, followed by optional flags);
 *  - any other string, a bare pattern (upstream's pattern string, compiled with the `g` flag).
 */
final class PiiDetectors
{
    private const EMAIL_PATTERN = '~\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b~';

    /** Basic shape only; validated with the Luhn algorithm. */
    private const CREDIT_CARD_PATTERN = '~\b(?:\d{4}[-\s]?){3}\d{4}\b~';

    private const IP_PATTERN = '~\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b~';

    private const MAC_ADDRESS_PATTERN = '~\b(?:[0-9A-Fa-f]{2}[:-]){5}(?:[0-9A-Fa-f]{2})\b~';

    private const URL_PATTERN = '~(?:https?://|www\.)[^\s<>"{}|\\\\^`\[\]]+~i';

    /** The built-in PII types and the detector for each. */
    public const BUILT_IN_TYPES = ['email', 'credit_card', 'ip', 'mac_address', 'url'];

    public const STRATEGIES = ['block', 'redact', 'mask', 'hash'];

    private function __construct()
    {
    }

    /**
     * Detect email addresses in content.
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    public static function detectEmail(string $content): array
    {
        return self::scan(self::EMAIL_PATTERN, $content);
    }

    /**
     * Detect credit card numbers in content (validated with the Luhn algorithm).
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    public static function detectCreditCard(string $content): array
    {
        $matches = [];
        foreach (self::scan(self::CREDIT_CARD_PATTERN, $content) as $match) {
            $cardNumber = (string) preg_replace('/\D/', '', $match['text']);
            // Credit cards are typically 13-19 digits.
            if (\strlen($cardNumber) >= 13 && \strlen($cardNumber) <= 19 && self::luhnCheck($cardNumber)) {
                $matches[] = $match;
            }
        }

        return $matches;
    }

    /**
     * Detect IP addresses in content (each octet validated to 0-255).
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    public static function detectIP(string $content): array
    {
        $matches = [];
        foreach (self::scan(self::IP_PATTERN, $content) as $match) {
            $parts = explode('.', $match['text']);
            $valid = \count($parts) === 4;
            foreach ($parts as $part) {
                $number = (int) $part;
                $valid = $valid && $number >= 0 && $number <= 255;
            }
            if ($valid) {
                $matches[] = $match;
            }
        }

        return $matches;
    }

    /**
     * Detect MAC addresses in content.
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    public static function detectMacAddress(string $content): array
    {
        return self::scan(self::MAC_ADDRESS_PATTERN, $content);
    }

    /**
     * Detect URLs in content (`http`/`https` and bare `www.`).
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    public static function detectUrl(string $content): array
    {
        return self::scan(self::URL_PATTERN, $content);
    }

    /**
     * Resolve a redaction rule to a concrete detector function.
     *
     * @param array{piiType: string, strategy: string, detector?: callable|string|null} $config
     * @return array{piiType: string, strategy: string, detector: callable}
     * @throws \InvalidArgumentException for an unknown PII type without a custom detector
     */
    public static function resolveRedactionRule(array $config): array
    {
        $detector = $config['detector'] ?? null;

        if ($detector !== null && $detector !== '') {
            // A string is always a pattern (never a function name), as in JavaScript.
            if (\is_string($detector)) {
                $pattern = self::toPattern($detector);
                $resolved = static fn (string $content): array => self::scan($pattern, $content);
            } else {
                $resolved = $detector;
            }
        } else {
            $resolved = match ($config['piiType']) {
                'email' => self::detectEmail(...),
                'credit_card' => self::detectCreditCard(...),
                'ip' => self::detectIP(...),
                'mac_address' => self::detectMacAddress(...),
                'url' => self::detectUrl(...),
                default => throw new \InvalidArgumentException(\sprintf(
                    'Unknown PII type: %s. Must be one of: %s, or provide a custom detector.',
                    $config['piiType'],
                    implode(', ', self::BUILT_IN_TYPES),
                )),
            };
        }

        return ['piiType' => $config['piiType'], 'strategy' => $config['strategy'], 'detector' => $resolved];
    }

    /**
     * Apply a strategy to content based on the matches.
     *
     * @param list<array{text: string, start: int, end: int}> $matches
     * @throws PiiDetectionError for the `block` strategy when there are matches
     * @throws \InvalidArgumentException for an unknown strategy
     */
    public static function applyStrategy(string $content, array $matches, string $strategy, string $piiType): string
    {
        if ($matches === []) {
            return $content;
        }

        return match ($strategy) {
            'block' => throw new PiiDetectionError($piiType, $matches),
            'redact' => self::replaceAll($content, $matches, static fn (array $match): string => '[REDACTED_' . mb_strtoupper($piiType) . ']'),
            'mask' => self::replaceAll($content, $matches, static fn (array $match): string => self::mask($match['text'], $piiType)),
            'hash' => self::replaceAll($content, $matches, static fn (array $match): string => '<' . $piiType . '_hash:' . substr(hash('sha256', $match['text']), 0, 8) . '>'),
            default => throw new \InvalidArgumentException("Unknown strategy: {$strategy}"),
        };
    }

    /**
     * Replace the matches back to front, so the earlier offsets stay valid.
     *
     * @param list<array{text: string, start: int, end: int}> $matches
     * @param callable(array{text: string, start: int, end: int}): string $replacement
     */
    private static function replaceAll(string $content, array $matches, callable $replacement): string
    {
        $result = $content;
        for ($i = \count($matches) - 1; $i >= 0; --$i) {
            $match = $matches[$i];
            $result = substr($result, 0, $match['start']) . $replacement($match) . substr($result, $match['end']);
        }

        return $result;
    }

    /** Partially mask PII, leaving the last few characters visible. */
    private static function mask(string $text, string $piiType): string
    {
        if ($piiType === 'credit_card') {
            // Show the last 4 digits: ****-****-****-1234
            $digits = (string) preg_replace('/\D/', '', $text);

            return '****-****-****-' . substr($digits, -4);
        }

        if ($piiType === 'email') {
            // Show the first character and the domain: j***@example.com
            $parts = explode('@', $text);
            $local = $parts[0];
            $domain = $parts[1] ?? '';
            if ($local !== '' && $domain !== '') {
                return mb_substr($local, 0, 1) . '***@' . $domain;
            }

            return '***';
        }

        // Default: show the last 4 characters.
        $length = mb_strlen($text);
        $visible = min(4, $length);

        return str_repeat('*', max(0, $length - $visible)) . mb_substr($text, $length - $visible);
    }

    /** The Luhn algorithm for credit card validation. */
    private static function luhnCheck(string $cardNumber): bool
    {
        $digits = (string) preg_replace('/\D/', '', $cardNumber);
        $sum = 0;
        $isEven = false;

        for ($i = \strlen($digits) - 1; $i >= 0; --$i) {
            $digit = (int) $digits[$i];
            if ($isEven) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $isEven = !$isEven;
        }

        return $sum % 10 === 0;
    }

    /**
     * Every non-overlapping match of a pattern, with its byte offsets.
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    private static function scan(string $pattern, string $content): array
    {
        $found = preg_match_all($pattern, $content, $all, \PREG_OFFSET_CAPTURE);
        if ($found === false) {
            throw new \InvalidArgumentException(\sprintf('Invalid PII detector pattern %s: %s', $pattern, preg_last_error_msg()));
        }

        $matches = [];
        foreach ($all[0] as [$text, $offset]) {
            $matches[] = ['text' => $text, 'start' => $offset, 'end' => $offset + \strlen($text)];
        }

        return $matches;
    }

    /** A bare pattern gets delimiters; a complete delimited PCRE is kept. */
    private static function toPattern(string $detector): string
    {
        if (preg_match('/^([\/~#%@!]).+\1[a-zA-Z]*$/s', $detector) === 1) {
            return $detector;
        }

        return '~' . str_replace('~', '\~', $detector) . '~';
    }
}
