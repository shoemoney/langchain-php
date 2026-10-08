<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * RFC 9562 UUID generation, parsing and validation (v1, v4, v5, v6, v7).
 *
 * Port of `@langchain/core/utils/uuid/*`, itself a vendored copy of `uuid`.
 *
 * `Uint8Array` becomes a PHP binary string (`"\x00..."`, 16 bytes for a UUID),
 * the buffer-and-offset overloads take the buffer by reference, and the options
 * object is an array with the same keys: `node`, `clockseq`, `random`, `rng`,
 * `msecs`, `nsecs`, `seq`. `RangeError` becomes {@see \RangeException} and
 * `TypeError` becomes {@see \InvalidArgumentException}.
 *
 * ## Ordering
 *
 * v6 and v7 ids exist so that they sort by creation time *as strings*, and the
 * stateful (no-options) generators guarantee that even when many ids are minted
 * inside one millisecond: the sub-millisecond counter is bumped, and when it
 * would overflow the millisecond itself is advanced rather than the counter
 * wrapped back to zero. That last point is a deliberate departure from upstream
 * (which resets the counter and so lets two ids in the same millisecond sort
 * backwards) and matches `CheckpointFunctions::uuid6()`, which fixed the same
 * defect after it surfaced as a resume loading the wrong checkpoint.
 */
final class Uuid
{
    public const NIL = '00000000-0000-0000-0000-000000000000';
    public const MAX = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
    public const DNS = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
    public const URL = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    private const REGEX = '/^(?:[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'
        . '|00000000-0000-0000-0000-000000000000|ffffffff-ffff-ffff-ffff-ffffffffffff)$/i';

    /** Offset from the Gregorian epoch to the unix epoch, in milliseconds. */
    private const GREGORIAN_OFFSET_MS = 12219292800000;

    /** @var array{node?: string|null, clockseq?: int, msecs?: int, nsecs?: int} */
    private static array $v1State = [];

    /** @var array{msecs?: int, seq?: int} */
    private static array $v7State = [];

    private function __construct()
    {
    }

    public static function validate(mixed $uuid): bool
    {
        return is_string($uuid) && preg_match(self::REGEX, $uuid) === 1;
    }

    /**
     * The version nibble of a UUID.
     *
     * @throws \InvalidArgumentException when `$uuid` is not a valid UUID
     */
    public static function version(string $uuid): int
    {
        if (!self::validate($uuid)) {
            throw new \InvalidArgumentException('Invalid UUID');
        }

        return (int) hexdec($uuid[14]);
    }

    /**
     * The 16 bytes of a UUID string.
     *
     * @throws \InvalidArgumentException when `$uuid` is not a valid UUID
     */
    public static function parse(string $uuid): string
    {
        if (!self::validate($uuid)) {
            throw new \InvalidArgumentException('Invalid UUID');
        }

        return (string) hex2bin(str_replace('-', '', $uuid));
    }

    /**
     * Format 16 bytes as a UUID string without checking the result.
     */
    public static function unsafeStringify(string $bytes, int $offset = 0): string
    {
        $hex = bin2hex(substr($bytes, $offset, 16));

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * Format 16 bytes as a UUID string, checking that the result is a valid UUID.
     *
     * @throws \InvalidArgumentException when the bytes do not form a valid UUID
     */
    public static function stringify(string $bytes, int $offset = 0): string
    {
        $uuid = self::unsafeStringify($bytes, $offset);
        if (!self::validate($uuid)) {
            throw new \InvalidArgumentException('Stringified UUID is invalid');
        }

        return $uuid;
    }

    /**
     * A random (version 4) UUID.
     *
     * @param array{random?: string, rng?: callable(): string}|null $options
     * @param string|null                                           $buf     when given, the bytes are written here and the buffer returned
     */
    public static function v4(?array $options = null, ?string &$buf = null, ?int $offset = null): string
    {
        $rnds = self::randomBytes($options);
        $rnds[6] = chr((ord($rnds[6]) & 0x0F) | 0x40);
        $rnds[8] = chr((ord($rnds[8]) & 0x3F) | 0x80);

        return self::emit($rnds, $buf, $offset);
    }

    /**
     * A name-based (version 5, SHA-1) UUID.
     *
     * @param string      $value     the name, as bytes (UTF-8 for text)
     * @param string      $namespace a UUID string, or its 16 raw bytes
     * @param string|null $buf
     */
    public static function v5(string $value, string $namespace, ?string &$buf = null, ?int $offset = null): string
    {
        $namespaceBytes = strlen($namespace) === 16 ? $namespace : self::parse($namespace);

        $bytes = substr(sha1($namespaceBytes . $value, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return self::emit($bytes, $buf, $offset);
    }

    /**
     * A time-based (version 1) UUID.
     *
     * With no options the id comes from monotonic internal state; with options it
     * is independent of that state and fully determined by them.
     *
     * @param array{node?: string, clockseq?: int, random?: string, rng?: callable(): string, msecs?: int, nsecs?: int, _v6?: bool}|null $options
     * @param string|null                                                                                                              $buf
     */
    public static function v1(?array $options = null, ?string &$buf = null, ?int $offset = null): string
    {
        $isV6 = (bool) ($options['_v6'] ?? false);
        if ($options !== null && array_keys($options) === ['_v6']) {
            $options = null;
        }

        if ($options !== null) {
            $bytes = self::v1Bytes(
                self::randomBytes($options),
                $options['msecs'] ?? null,
                $options['nsecs'] ?? null,
                $options['clockseq'] ?? null,
                $options['node'] ?? null,
            );
        } else {
            $rnds = random_bytes(16);
            self::updateV1State(self::$v1State, self::nowMs(), $rnds);
            $bytes = self::v1Bytes(
                $rnds,
                self::$v1State['msecs'],
                self::$v1State['nsecs'],
                // v6 draws a fresh clockseq and node every time (RFC 9562 5.6).
                $isV6 ? null : self::$v1State['clockseq'],
                $isV6 ? null : self::$v1State['node'],
            );
        }

        return self::emit($bytes, $buf, $offset);
    }

    /**
     * A reordered-time (version 6) UUID: v1's timestamp laid out so ids sort by time.
     *
     * @param array{node?: string, clockseq?: int, random?: string, rng?: callable(): string, msecs?: int, nsecs?: int}|null $options
     * @param string|null                                                                                                     $buf
     */
    public static function v6(?array $options = null, ?string &$buf = null, ?int $offset = null): string
    {
        $v1 = self::v1(['_v6' => true] + ($options ?? []));
        $bytes = self::v1BytesToV6(self::parse($v1));

        return self::emit($bytes, $buf, $offset);
    }

    /**
     * Convert a v1 UUID (string, or 16 raw bytes) to the v6 UUID of the same instant.
     *
     * The result has the same form as the input.
     */
    public static function v1ToV6(string $uuid): string
    {
        if (strlen($uuid) === 16) {
            return self::v1BytesToV6($uuid);
        }

        return self::unsafeStringify(self::v1BytesToV6(self::parse($uuid)));
    }

    /**
     * A unix-time-ordered (version 7) UUID.
     *
     * @param array{random?: string, rng?: callable(): string, msecs?: int, seq?: int}|null $options
     * @param string|null                                                                    $buf
     */
    public static function v7(?array $options = null, ?string &$buf = null, ?int $offset = null): string
    {
        if ($options !== null) {
            $bytes = self::v7Bytes(
                self::randomBytes($options),
                $options['msecs'] ?? null,
                $options['seq'] ?? null,
            );
        } else {
            $rnds = random_bytes(16);
            self::updateV7State(self::$v7State, self::nowMs(), $rnds);
            $bytes = self::v7Bytes($rnds, self::$v7State['msecs'], self::$v7State['seq']);
        }

        return self::emit($bytes, $buf, $offset);
    }

    /**
     * Advance the stateful v1/v6 clock.
     *
     * A clock that stands still or runs backwards is pinned to the last
     * millisecond handed out and the 100ns counter is bumped instead, so ids
     * never go backwards; on counter overflow the millisecond advances.
     *
     * @param array{node?: string|null, clockseq?: int, msecs?: int, nsecs?: int} $state
     */
    private static function updateV1State(array &$state, int $now, string $rnds): void
    {
        $state['msecs'] ??= PHP_INT_MIN;
        $state['nsecs'] ??= 0;
        $state['node'] ??= null;

        if ($now <= $state['msecs']) {
            $now = $state['msecs'];
            $state['nsecs']++;
            if ($state['nsecs'] >= 10000) {
                $state['node'] = null;
                $state['nsecs'] = 0;
                $now++;
            }
        } else {
            $state['nsecs'] = 0;
        }

        if ($state['node'] === null) {
            $node = substr($rnds, 10, 6);
            $node[0] = chr(ord($node[0]) | 0x01); // multicast bit
            $state['node'] = $node;
            $state['clockseq'] = ((ord($rnds[8]) << 8) | ord($rnds[9])) & 0x3FFF;
        }

        $state['msecs'] = $now;
    }

    private static function v1Bytes(
        string $rnds,
        ?int $msecs,
        ?int $nsecs,
        ?int $clockseq,
        ?string $node,
    ): string {
        if (strlen($rnds) < 16) {
            throw new \InvalidArgumentException('Random bytes length must be >= 16');
        }

        $msecs ??= self::nowMs();
        $nsecs ??= 0;
        $clockseq ??= ((ord($rnds[8]) << 8) | ord($rnds[9])) & 0x3FFF;
        $node ??= substr($rnds, 10, 6);

        $msecs += self::GREGORIAN_OFFSET_MS;

        $tl = (($msecs & 0xFFFFFFF) * 10000 + $nsecs) % 0x100000000;
        $tmh = ((int) (($msecs / 0x100000000) * 10000)) & 0xFFFFFFF;

        return chr(($tl >> 24) & 0xFF) . chr(($tl >> 16) & 0xFF) . chr(($tl >> 8) & 0xFF) . chr($tl & 0xFF)
            . chr(($tmh >> 8) & 0xFF) . chr($tmh & 0xFF)
            . chr((($tmh >> 24) & 0x0F) | 0x10) . chr(($tmh >> 16) & 0xFF)
            . chr((($clockseq >> 8) & 0xFF) | 0x80) . chr($clockseq & 0xFF)
            . substr($node, 0, 6);
    }

    private static function v1BytesToV6(string $v): string
    {
        $b = array_values(unpack('C*', $v) ?: []);

        return chr((($b[6] & 0x0F) << 4) | (($b[7] >> 4) & 0x0F))
            . chr((($b[7] & 0x0F) << 4) | (($b[4] & 0xF0) >> 4))
            . chr((($b[4] & 0x0F) << 4) | (($b[5] & 0xF0) >> 4))
            . chr((($b[5] & 0x0F) << 4) | (($b[0] & 0xF0) >> 4))
            . chr((($b[0] & 0x0F) << 4) | (($b[1] & 0xF0) >> 4))
            . chr((($b[1] & 0x0F) << 4) | (($b[2] & 0xF0) >> 4))
            . chr(0x60 | ($b[2] & 0x0F))
            . chr($b[3])
            . substr($v, 8, 8);
    }

    /**
     * @param array{msecs?: int, seq?: int} $state
     */
    private static function updateV7State(array &$state, int $now, string $rnds): void
    {
        $state['msecs'] ??= PHP_INT_MIN;
        $state['seq'] ??= 0;

        if ($now > $state['msecs']) {
            $state['seq'] = ((ord($rnds[6]) << 23) | (ord($rnds[7]) << 16) | (ord($rnds[8]) << 8) | ord($rnds[9])) & 0xFFFFFFFF;
            $state['msecs'] = $now;
        } else {
            $state['seq'] = ($state['seq'] + 1) & 0xFFFFFFFF;
            if ($state['seq'] === 0) {
                $state['msecs']++;
            }
        }
    }

    private static function v7Bytes(string $rnds, ?int $msecs, ?int $seq): string
    {
        if (strlen($rnds) < 16) {
            throw new \InvalidArgumentException('Random bytes length must be >= 16');
        }

        $msecs ??= self::nowMs();
        $seq ??= (((ord($rnds[6]) * 0x7F) << 24) | (ord($rnds[7]) << 16) | (ord($rnds[8]) << 8) | ord($rnds[9])) & 0xFFFFFFFF;
        $seq &= 0xFFFFFFFF;

        return chr(intdiv($msecs, 0x10000000000) & 0xFF)
            . chr(intdiv($msecs, 0x100000000) & 0xFF)
            . chr(intdiv($msecs, 0x1000000) & 0xFF)
            . chr(intdiv($msecs, 0x10000) & 0xFF)
            . chr(intdiv($msecs, 0x100) & 0xFF)
            . chr($msecs & 0xFF)
            . chr(0x70 | (($seq >> 28) & 0x0F))
            . chr(($seq >> 20) & 0xFF)
            . chr(0x80 | (($seq >> 14) & 0x3F))
            . chr(($seq >> 6) & 0xFF)
            . chr((($seq << 2) & 0xFF) | (ord($rnds[10]) & 0x03))
            . substr($rnds, 11, 5);
    }

    /**
     * @param array{random?: string, rng?: callable(): string}|null $options
     */
    private static function randomBytes(?array $options): string
    {
        $rnds = $options['random'] ?? (isset($options['rng']) ? ($options['rng'])() : random_bytes(16));
        if (strlen($rnds) < 16) {
            throw new \InvalidArgumentException('Random bytes length must be >= 16');
        }

        return $rnds;
    }

    /**
     * Return the string form, or write the bytes into the caller's buffer.
     */
    private static function emit(string $bytes, ?string &$buf, ?int $offset): string
    {
        if ($buf === null) {
            return self::unsafeStringify($bytes);
        }

        $offset ??= 0;
        if ($offset < 0 || $offset + 16 > strlen($buf)) {
            throw new \RangeException(sprintf(
                'UUID byte range %d:%d is out of buffer bounds',
                $offset,
                $offset + 15,
            ));
        }

        $buf = substr_replace($buf, substr($bytes, 0, 16), $offset, 16);

        return $buf;
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
