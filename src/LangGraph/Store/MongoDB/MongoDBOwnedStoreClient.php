<?php

declare(strict_types=1);

namespace LangGraph\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;

/**
 * @internal The lazy client lease created by {@see MongoDBStore::fromConnString()}.
 * Releases PHP references only; Manager destruction and socket closure are not guaranteed.
 */
class MongoDBOwnedStoreClient extends MongoDBDriverStoreClient
{
    private ?object $native = null;

    private bool $released = false;

    /** @param \Closure(array<string, mixed>): \MongoDB\Client $connect */
    public function __construct(private readonly \Closure $connect, private array $driverOptions)
    {
        // The parent requires an already-created native client. This lease creates it lazily.
    }

    public function appendMetadata(array $metadata): void
    {
        if ($this->native === null && !$this->released) {
            // PHP adaptation: caller keys win, unlike Node's appendMetadata entry accumulation.
            $this->driverOptions['driver'] = ($this->driverOptions['driver'] ?? []) + $metadata;
        }
    }

    public function db(?string $name = null): MongoDatabaseInterface
    {
        if ($name === null || $name === '') {
            throw new \InvalidArgumentException('MongoDBStore::fromConnString() needs a dbName.');
        }
        $resolve = fn (): MongoDatabaseInterface => $this->wrapDatabase($this->nativeClient()->selectDatabase($name));

        // Hold only the lease resolver and name, never a cached native database or collection.
        return new class ($resolve) implements MongoDatabaseInterface {
            public function __construct(private readonly \Closure $resolve)
            {
            }

            public function collection(string $name): MongoCollectionInterface
            {
                return ($this->resolve)()->collection($name);
            }
        };
    }

    /** Drop this lease's reference; repeat calls do nothing. */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        // Set before dropping a reference: even a throwing destructor leaves the lease released.
        $this->released = true;
        $this->native = null;
    }

    protected function nativeClient(): object
    {
        if ($this->released) {
            throw new \LogicException('MongoDBStore was stopped: its owned client has been released.');
        }

        return $this->native ??= ($this->connect)($this->driverOptions);
    }
}
