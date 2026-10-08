<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Utils\NamespaceUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

class NsBaseError extends \Exception
{
}

class NsSubError extends NsBaseError
{
}

class NsConfigError extends NsSubError
{
}

class NsAuthError extends NsSubError
{
}

class NsOtherError extends \Exception
{
}

class NsSerializable
{
    /** @return array{type: string} */
    public function toJSON(): array
    {
        return ['type' => (new \ReflectionClass($this))->getShortName()];
    }
}

class NsBaseMessage extends NsSerializable
{
    public function __construct(public string $content)
    {
    }
}

class NsAIMessage extends NsBaseMessage
{
}

/**
 * Port of `utils/tests/namespace.test.ts`.
 *
 * Upstream brands classes by extending the return of `ns.brand(Base)`. PHP cannot
 * mint a subclass at runtime, so the classes above are declared plainly and
 * branded by registration; everything the upstream cases assert (instanceof is
 * untouched, isInstance is hierarchical, sibling/parent/unrelated are rejected,
 * paths are isolated) is checked against that.
 */
#[CoversClass(NamespaceUtils::class)]
final class NamespaceUtilsTest extends TestCase
{
    private NamespaceUtils $errorNs;
    private NamespaceUtils $subNs;
    private NamespaceUtils $base;
    private NamespaceUtils $sub;
    private NamespaceUtils $config;
    private NamespaceUtils $auth;
    private NamespaceUtils $other;

    protected function setUp(): void
    {
        $root = NamespaceUtils::createNamespace('test');
        $this->errorNs = $root->sub('error');
        $this->subNs = $this->errorNs->sub('sub');

        $this->base = $this->errorNs->brand(NsBaseError::class);
        $this->sub = $this->subNs->brand(NsSubError::class);
        $this->config = $this->subNs->brand(NsConfigError::class, 'configuration');
        $this->auth = $this->subNs->brand(NsAuthError::class, 'auth');
        $this->other = $root->sub('other')->brand(NsOtherError::class);
    }

    public function testBrandedClassesPreserveInstanceof(): void
    {
        $config = new NsConfigError('config');

        self::assertInstanceOf(\Exception::class, $config);
        self::assertInstanceOf(NsConfigError::class, $config);
        self::assertInstanceOf(NsSubError::class, $config);
        self::assertInstanceOf(NsBaseError::class, $config);
        self::assertNotInstanceOf(NsOtherError::class, $config);
        self::assertNotInstanceOf(NsSubError::class, new NsOtherError('other'));
        self::assertNotInstanceOf(NsBaseError::class, new NsOtherError('other'));
    }

    public function testLeafIsInstanceRecognisesItsOwnInstances(): void
    {
        self::assertTrue($this->config->isInstance(new NsConfigError('c')));
    }

    public function testLeafIsInstanceRejectsSiblings(): void
    {
        self::assertFalse($this->config->isInstance(new NsAuthError('a')));
    }

    public function testLeafIsInstanceRejectsParents(): void
    {
        self::assertFalse($this->config->isInstance(new NsSubError('s')));
    }

    public function testMidLevelRecognisesItself(): void
    {
        self::assertTrue($this->sub->isInstance(new NsSubError('s')));
    }

    public function testMidLevelRecognisesChildrenViaTheClassChain(): void
    {
        self::assertTrue($this->sub->isInstance(new NsConfigError('c')));
        self::assertTrue($this->sub->isInstance(new NsAuthError('a')));
    }

    public function testMidLevelRejectsParents(): void
    {
        self::assertFalse($this->sub->isInstance(new NsBaseError('b')));
    }

    public function testMidLevelRejectsUnrelatedInstances(): void
    {
        self::assertFalse($this->sub->isInstance(new NsOtherError('o')));
    }

    public function testRootBrandRecognisesItselfAndAllDescendants(): void
    {
        self::assertTrue($this->base->isInstance(new NsBaseError('b')));
        self::assertTrue($this->base->isInstance(new NsSubError('s')));
        self::assertTrue($this->base->isInstance(new NsConfigError('c')));
        self::assertTrue($this->base->isInstance(new NsAuthError('a')));
    }

    public function testRootBrandRejectsUnrelatedBrandedErrors(): void
    {
        self::assertFalse($this->base->isInstance(new NsOtherError('o')));
    }

    public function testNamespaceLevelRecognisesAnythingBrandedUnderIt(): void
    {
        self::assertTrue($this->errorNs->isInstance(new NsBaseError('b')));
        self::assertTrue($this->errorNs->isInstance(new NsSubError('s')));
        self::assertTrue($this->errorNs->isInstance(new NsConfigError('c')));
    }

    public function testNamespaceLevelRejectsOtherNamespaces(): void
    {
        self::assertFalse($this->errorNs->isInstance(new NsOtherError('o')));
    }

    public function testChildNamespaceRecognisesItsOwnInstances(): void
    {
        self::assertTrue($this->subNs->isInstance(new NsSubError('s')));
        self::assertTrue($this->subNs->isInstance(new NsConfigError('c')));
    }

    public function testChildNamespaceRejectsParentOnlyInstances(): void
    {
        self::assertFalse($this->subNs->isInstance(new NsBaseError('b')));
    }

    public function testEdgeCasesAreFalse(): void
    {
        self::assertFalse($this->base->isInstance(null));
        self::assertFalse($this->base->isInstance(42));
        self::assertFalse($this->base->isInstance('string'));
        self::assertFalse($this->base->isInstance(true));
        self::assertFalse($this->base->isInstance([]));
        self::assertFalse($this->base->isInstance(new \stdClass()));
        self::assertFalse($this->base->isInstance(new \Exception('plain')));
    }

    public function testErrorPropertiesArePreserved(): void
    {
        $err = new NsConfigError('hello');

        self::assertSame('hello', $err->getMessage());
        self::assertNotSame('', $err->getTraceAsString());
    }

    public function testDifferentRootNamespacesAreIndependent(): void
    {
        $a = NamespaceUtils::createNamespace('isolated.a')->brand(NsOtherError::class);
        $b = NamespaceUtils::createNamespace('isolated.b')->brand(NsBaseError::class);

        self::assertTrue($a->isInstance(new NsOtherError('a')));
        self::assertTrue($b->isInstance(new NsBaseError('b')));
        self::assertFalse($a->isInstance(new NsBaseError('b')));
        self::assertFalse($b->isInstance(new NsOtherError('a')));
    }

    public function testTheSameMarkerUnderDifferentNamespacesIsIndependent(): void
    {
        $a = NamespaceUtils::createNamespace('collision.a')->brand(NsOtherError::class, 'config');
        $b = NamespaceUtils::createNamespace('collision.b')->brand(NsBaseError::class, 'config');

        self::assertTrue($a->isInstance(new NsOtherError('a')));
        self::assertTrue($b->isInstance(new NsBaseError('b')));
        self::assertFalse($a->isInstance(new NsBaseError('b')));
        self::assertFalse($b->isInstance(new NsOtherError('a')));
    }

    public function testBrandingNonErrorBaseClasses(): void
    {
        $msgNs = NamespaceUtils::createNamespace('test.message');
        $baseMessage = $msgNs->brand(NsBaseMessage::class);
        $ai = $msgNs->brand(NsAIMessage::class, 'ai');
        $msg = new NsAIMessage('hello');

        self::assertSame(['type' => 'NsAIMessage'], $msg->toJSON());
        self::assertSame('hello', $msg->content);
        self::assertTrue($baseMessage->isInstance($msg));
        self::assertTrue($ai->isInstance($msg));
        self::assertInstanceOf(NsSerializable::class, $msg);
        self::assertInstanceOf(NsBaseMessage::class, $msg);
        self::assertFalse($this->base->isInstance($msg));
    }

    public function testSymbolsFollowTheDottedPath(): void
    {
        self::assertSame('test.error.sub', $this->subNs->symbol());
        self::assertSame('test.error.sub.configuration', $this->config->symbol());
        self::assertSame('langchain', NamespaceUtils::ns()->symbol());
        self::assertSame(NamespaceUtils::ns(), NamespaceUtils::ns());
    }

    public function testBrandingAnUnknownClassIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->errorNs->brand('No\\Such\\ClassHere');
    }
}
