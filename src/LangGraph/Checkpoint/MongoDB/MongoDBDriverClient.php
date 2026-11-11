<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\MongoDB;

/**
 * Adapts a borrowed optional mongodb/mongodb client; the caller owns its lifecycle.
 *
 * PHP requires an explicit database name. Supply the connection's intended default
 * here when MongoDBSaver will not supply its own dbName. No URI or test database is inferred.
 */
class MongoDBDriverClient implements MongoClientInterface
{
    protected ?string $defaultDbName = null;

    public function __construct(private readonly \MongoDB\Client $client, ?string $defaultDbName = null)
    {
        $this->defaultDbName = $defaultDbName;
    }

    public function appendMetadata(array $metadata): void
    {
        // PHP handshake metadata is fixed when the native client is constructed.
        // The caller may supply driverOptions['driver'] before wrapping it; an
        // already-created borrowed client cannot be tagged dynamically.
    }

    public function db(?string $name = null): MongoDatabaseInterface
    {
        $name ??= $this->defaultDbName;
        if ($name === null) {
            throw new \InvalidArgumentException('Supply MongoDBSaver dbName or MongoDBDriverClient defaultDbName.');
        }

        return $this->wrapDatabase($this->nativeClient()->selectDatabase($name));
    }

    /** @return \MongoDB\Client */
    protected function nativeClient(): object
    {
        return $this->client;
    }

    /** @param \MongoDB\Database $database */
    protected function wrapDatabase(object $database): MongoDatabaseInterface
    {
        return new MongoDBDriverDatabase($database);
    }
}
