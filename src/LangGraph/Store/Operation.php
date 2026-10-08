<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * One unit of work for {@see BaseStore::batch()}.
 *
 * Port of the `Operation` union in `store/base.ts`: a get, a search, a put (or
 * delete) and a namespace listing. The TypeScript union is discriminated by
 * which keys are present on a plain object; PHP discriminates by class, so a
 * delete is a {@see PutOperation} whose value is null, exactly as upstream.
 */
interface Operation
{
}
