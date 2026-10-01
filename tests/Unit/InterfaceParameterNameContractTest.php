<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every implementation must spell its parameters exactly as the interface does.
 *
 * This is not style. PHP binds a NAMED argument to the *implementing* class's
 * parameter name, so an implementation that renames one satisfies the interface
 * and then dies at the call site with "Unknown named parameter" — a fatal that
 * only appears when some caller happens to pass that argument by name.
 *
 * The port has already been bitten by exactly this: `HttpClient` call sites used
 * named arguments, and any `HttpClient` spelling its parameter `$t` instead of
 * `$timeout` failed despite satisfying the interface. The callers were made
 * positional, which treats the symptom. The hazard itself is still live for every
 * future implementation.
 *
 * WHY A GENERATED MOCK CANNOT FIND THIS. PHPUnit builds a double from the
 * INTERFACE, so the double's signature is the interface's — the test passes
 * against an implementation whose names have drifted, because the double never
 * routes through it. This defect is structurally invisible to mock-based tests,
 * which is the same reason the one existing guard (`TransportExceptionContractTest`)
 * uses a HAND-WRITTEN double with deliberately alien parameter names. Reflection
 * is the only instrument that sees it.
 *
 * Measured on this tree as written: 3 interfaces, 386 overridden methods, 0 drift.
 * So this guard pins a property that is currently true, and the sweep asserts a
 * floor on its own coverage so an empty or broken sweep fails rather than passes.
 */
final class InterfaceParameterNameContractTest extends TestCase
{
    /** Interfaces this contract covers, with the source tree they live in. */
    private const ROOTS = ['src/LangChain', 'src/LangGraph'];

    /**
     * @return list<ReflectionClass<object>>
     */
    private static function interfaces(): array
    {
        $root = \dirname(__DIR__, 2);
        $out = [];
        foreach (self::ROOTS as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            ) as $f) {
                if (!$f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($f->getPathname());
                if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns) !== 1) {
                    continue;
                }
                if (preg_match_all('/^(?:abstract\s+|final\s+)?interface\s+(\w+)/m', $src, $names) < 1) {
                    continue;
                }
                foreach ($names[1] as $name) {
                    $fqcn = trim($ns[1]) . '\\' . $name;
                    if (interface_exists($fqcn)) {
                        $out[] = new ReflectionClass($fqcn);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return list<class-string>
     */
    private static function typesIn(array $roots): array
    {
        $root = \dirname(__DIR__, 2);
        $out = [];
        foreach ($roots as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            ) as $f) {
                if (!$f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($f->getPathname());
                if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns) !== 1) {
                    continue;
                }
                $out[] = trim($ns[1]) . '\\' . $f->getBasename('.php');
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function parameterNames(ReflectionMethod $m): array
    {
        $names = [];
        foreach ($m->getParameters() as $p) {
            $names[] = $p->getName();
        }

        return $names;
    }

    public function testImplementationsDoNotRenameInterfaceParameters(): void
    {
        $interfaces = self::interfaces();
        $types = self::typesIn(self::ROOTS);

        // CONTROL. A sweep that examined nothing would report "0 drift" and read
        // as a pass, which is indistinguishable from a clean tree. This is the
        // guard's own version of the rule the rest of this project keeps
        // re-learning: a zero from a command that did not run is not a zero.
        self::assertNotEmpty($interfaces, 'no interfaces found — the sweep is blind, not clean');
        self::assertContains(
            \LangChain\Utils\Http\HttpClient::class,
            array_map(static fn (ReflectionClass $r): string => $r->getName(), $interfaces),
            'the sweep must actually reach HttpClient, the interface this defect was first found on',
        );

        $drift = [];
        $examined = 0;

        foreach ($interfaces as $iface) {
            foreach ($types as $type) {
                if (!class_exists($type)) {
                    continue;
                }
                $rc = new ReflectionClass($type);
                if ($rc->isInterface() || !$rc->implementsInterface($iface->getName())) {
                    continue;
                }
                foreach ($iface->getMethods() as $im) {
                    if ($im->isStatic() || !$rc->hasMethod($im->getName())) {
                        continue;
                    }
                    $cm = $rc->getMethod($im->getName());
                    // Not overridden: the interface's own signature is in force.
                    if ($cm->getDeclaringClass()->getName() === $iface->getName()) {
                        continue;
                    }
                    ++$examined;
                    $want = self::parameterNames($im);
                    $got = self::parameterNames($cm);
                    if ($want !== $got) {
                        $drift[] = sprintf(
                            '%s::%s() declares (%s) but %s declares (%s) — a caller passing named '
                            . 'arguments dies with "Unknown named parameter"',
                            $iface->getShortName(),
                            $im->getName(),
                            implode(', ', $want) ?: '-',
                            $rc->getShortName(),
                            implode(', ', $got) ?: '-',
                        );
                    }
                }
            }
        }

        self::assertGreaterThan(
            100,
            $examined,
            'the sweep examined almost nothing, so "no drift" would be an empty result, not a finding',
        );
        self::assertSame([], $drift, "parameter names drifted from the interface:\n  " . implode("\n  ", $drift));
    }

    /**
     * A narrower, sharper guard on the interface where this actually bit.
     *
     * `HttpClient` is the one case with a dedicated hand-written double, and its
     * call sites are documented as positional because a named argument binds to
     * the implementing class's name. This pins the two spellings themselves, so a
     * rename cannot pass unnoticed even if every implementation is renamed in the
     * same commit — which is the only way this defect could plausibly arrive.
     *
     * The interface has exactly two methods, `post` and `postStream`; an earlier
     * draft of this test also asserted a `get`, which does not exist. That is the
     * project's own standing rule — never guess an API, read it — so the assertion
     * list below is derived from the interface rather than remembered.
     */
    public function testHttpClientParameterNamesAreTheDocumentedOnes(): void
    {
        $iface = new ReflectionClass(\LangChain\Utils\Http\HttpClient::class);

        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            $iface->getMethods(),
        );
        sort($methods);
        self::assertSame(
            ['post', 'postStream'],
            $methods,
            'HttpClient gained or lost a method — this test enumerates them explicitly and must be updated',
        );

        $expected = ['url', 'headers', 'body', 'query', 'timeout'];
        foreach ($methods as $method) {
            self::assertSame(
                $expected,
                self::parameterNames($iface->getMethod($method)),
                \sprintf(
                    'HttpClient::%s() parameter names changed. Call sites are POSITIONAL precisely because '
                    . 'these names are not contractual across implementations (a named argument binds to '
                    . 'the implementing class\'s name, which is how this was originally found). If this '
                    . 'change is deliberate, the positional call-site note in HANDOFF needs revisiting too.',
                    $method,
                ),
            );
        }
    }
}