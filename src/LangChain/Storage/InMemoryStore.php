<?php

declare(strict_types=1);

namespace LangChain\Storage;

use LangChain\Stores\InMemoryStore as CoreInMemoryStore;

/**
 * Port of `langchain/storage/in_memory`, which upstream defines as a bare
 * re-export of core's `InMemoryStore`. PHP has no re-export, so this is the
 * thinnest possible subclass; it adds nothing.
 *
 * @template T
 * @template-extends CoreInMemoryStore<T>
 */
class InMemoryStore extends CoreInMemoryStore
{
}
