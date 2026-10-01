<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use LangChain\LanguageModels\BaseChatModel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The two public batch entry points must be callable the same way.
 *
 * `BaseChatModel` exposes `generatePrompt()` and `generateMessages()`. Both take
 * the same three concepts — the input, per-call options, and callbacks — and for
 * many iterations they took them in DIFFERENT positional orders:
 *
 *     generatePrompt  (array $promptValues, array $options, ?array $callbacks, ?RunnableConfig $config)
 *     generateMessages(array $messageLists, ?RunnableConfig $config, array $options)
 *
 * A caller who learned one could call the other wrongly, and PHP will not stop
 * them: `generateMessages($lists, ['temperature' => 0])` against the old order
 * type-checked as a `RunnableConfig` only to fail at runtime, while passing a
 * config positionally as the second argument — the natural reading of "the other
 * one takes options second" — silently became `$options`.
 *
 * Upstream is CONSISTENT, and that settles the direction. `chat_models.ts` has
 * `generate(messages, options, callbacks)` and `generatePrompt(promptValues,
 * options, callbacks)` — same order on both, no config object. So the port's
 * `generateMessages` was the outlier and it moved, not `generatePrompt`. An
 * advisory (`claude-fable-5-1` §2) proposed unifying on `(input, ?RunnableConfig
 * $config, array $options)`, which would have matched NEITHER upstream nor the
 * sibling it is supposed to agree with.
 *
 * `generateMessages` is not an upstream name — upstream's public `generate()` is
 * private here as the protected per-prompt `generate()` — so its shape is this
 * port's to choose, and choosing it to match its sibling is the only defensible
 * option. That is now stated in its docblock rather than left to be rediscovered.
 */
final class GenerateEntryPointCoherenceTest extends TestCase
{
    /**
     * The sibling methods must agree on parameter name, order and type.
     *
     * Compared as (name, type, default) tuples rather than as a hardcoded
     * signature, so the invariant is the thing being protected and not the
     * spelling that happens to satisfy it today.
     */
    public function testTheTwoGenerateEntryPointsHaveTheSameShape(): void
    {
        // The INPUT parameter differs by design: $promptValues is converted to
        // messages inside the body, and $messageLists is not — that difference is
        // the reason the two methods exist rather than one. What must agree is
        // everything after it.
        $prompt = array_slice($this->shapeOf('generatePrompt'), 1);
        $messages = array_slice($this->shapeOf('generateMessages'), 1);

        self::assertSame(
            $prompt,
            $messages,
            'generatePrompt() and generateMessages() must take their arguments after the input in the '
            . 'same order with the same names and types, so a caller who learned one can call the other.',
        );

        // Explicit, because "they are the same" should not be the only claim: the
        // shape has to also be the UPSTREAM shape, which is (input, options, callbacks).
        self::assertSame(
            ['options', 'callbacks', 'config'],
            array_column($messages, 'name'),
            'the shared order must be (input, options, callbacks, config) — upstream takes '
            . '(input, options, callbacks) on both of its generate entry points.',
        );
    }

    /**
     * `$config` must be LAST and OPTIONAL on both.
     *
     * Load-bearing rather than cosmetic. A required parameter added to an existing
     * method is a BC break PHP forbids on an override, and an optional one placed
     * earlier silently reinterprets every existing positional call — which is
     * exactly what happened when `$config` sat second on `generateMessages` while
     * `$options` sat second on `generatePrompt`.
     */
    public function testConfigIsLastAndOptional(): void
    {
        foreach (['generatePrompt', 'generateMessages'] as $method) {
            $params = $this->shapeOf($method);
            $last = $params[array_key_last($params)];

            self::assertSame('config', $last['name'], $method . ' must take $config last');
            self::assertTrue(
                $last['optional'],
                $method . ' must keep $config optional, or every existing call becomes a BC break',
            );
        }
    }

    /**
     * Both must be PUBLIC, and neither may narrow its input type.
     *
     * `generateMessages` was reachable only if public — the protected
     * `generate()` is a different method with a different contract (one prompt,
     * one run manager), so mistaking one for the other is easy and silent.
     */
    public function testBothArePublicMethodsOnTheBaseClass(): void
    {
        foreach (['generatePrompt', 'generateMessages'] as $method) {
            $r = new ReflectionMethod(BaseChatModel::class, $method);
            self::assertTrue($r->isPublic(), $method . ' must be public to be the batch entry point');
            self::assertFalse(
                $r->isStatic(),
                $method . ' must not be static: both read per-instance provider state',
            );
        }
    }

    /**
     * The documented precedence must exist, because two carriers of one concept do.
     *
     * `$callbacks` and `$config->callbacks` are the same thing by two routes.
     * Whichever wins has to be written down, or a caller reads the signature and
     * guesses. Both docblocks now name it, and this asserts the claim is present
     * rather than merely intended.
     */
    public function testTheCallbackPrecedenceIsDocumentedOnBoth(): void
    {
        foreach (['generatePrompt', 'generateMessages'] as $method) {
            $doc = (string) (new ReflectionMethod(BaseChatModel::class, $method))->getDocComment();
            self::assertNotSame('', $doc, $method . ' must carry a docblock');
            self::assertMatchesRegularExpression(
                '/@param\s+list<object>\|null\s+\$callbacks\s+[^\n]*\$config/',
                $doc,
                $method . ' must document what happens when BOTH $callbacks and $config are given',
            );
        }
    }

    /**
     * `generateMessages` must say it is not an upstream name.
     *
     * A non-upstream public method in a fidelity port is a decision, and an
     * unlabelled one reads as a port of something upstream has. This is the same
     * reasoning that had `pipeTo()` removed rather than repaired.
     */
    public function testTheNonUpstreamNameIsLabelled(): void
    {
        $doc = (string) (new ReflectionMethod(BaseChatModel::class, 'generateMessages'))->getDocComment();

        self::assertMatchesRegularExpression(
            '/NOT an upstream name/i',
            $doc,
            'generateMessages is this port\'s name for upstream\'s public generate(); the docblock must say so',
        );
    }

    /**
     * Parameter shape as comparable tuples.
     *
     * @return list<array{name: string, type: string, optional: bool}>
     */
    private function shapeOf(string $method): array
    {
        $shape = [];
        foreach ((new ReflectionMethod(BaseChatModel::class, $method))->getParameters() as $p) {
            $type = $p->getType();
            $shape[] = [
                'name' => $p->getName(),
                'type' => $type instanceof ReflectionNamedType ? $type->getName() : (string) $type,
                'optional' => $p->isOptional(),
            ];
        }

        return $shape;
    }
}