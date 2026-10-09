<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * Marker for transformers provided by internal products (the lifecycle, subgraph discovery, tool call and
 * subagent transformers).
 *
 * Port of `NativeStreamTransformer` (the `__native: true` brand). A native transformer's projection lands
 * directly on the run stream instead of in `extensions`, and is never auto-forwarded as `custom:<name>`
 * events by {@see Mux::wireChannels()}.
 */
interface NativeStreamTransformer extends StreamTransformer
{
}
