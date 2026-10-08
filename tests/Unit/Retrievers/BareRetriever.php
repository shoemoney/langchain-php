<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Retrievers;

use LangChain\Retrievers\BaseRetriever;

/**
 * A retriever that implements nothing, to observe the base class's default.
 */
final class BareRetriever extends BaseRetriever
{
    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['test', 'bare'];
    }
}
