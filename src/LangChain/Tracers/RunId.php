<?php

declare(strict_types=1);

namespace LangChain\Tracers;

use Ramsey\Uuid\Uuid;

/**
 * Generate the run ids the callback machinery hands out.
 *
 * The TypeScript original calls `uuidv7()` inline in a dozen places. Centralising
 * it here means one place to stub in a test that needs deterministic ids, and
 * one place that documents *why* these are v7 and not v4.
 *
 * They are v7 because LangSmith sorts traces by `dotted_order`, which embeds the
 * run id as its final tie-break segment. A v7 id is time-ordered, so ids minted
 * in sequence also sort in sequence — which makes the tie-break meaningful
 * instead of random. A v4 id would make same-millisecond siblings order
 * arbitrarily.
 */
final class RunId
{
    private function __construct()
    {
    }

    /**
     * A fresh time-ordered run id.
     */
    public static function v7(): string
    {
        return Uuid::uuid7()->toString();
    }
}
