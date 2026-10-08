<?php

declare(strict_types=1);

namespace LangGraph\Graph;

use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;

/**
 * A {@see StateGraph} whose whole state is one list of messages.
 *
 * Port of `MessageGraph` from `langgraph-core/src/graph/message.ts`.
 *
 * The state is a single `__root__` channel reduced with
 * {@see MessagesReducer::messagesStateReducer()} and defaulting to `[]`. Nodes therefore receive the
 * message list itself (not a keyed map), and whatever message or list of messages they return is
 * appended — or, when it carries an id already present, replaces that message.
 */
class MessageGraph extends StateGraph
{
    public function __construct()
    {
        parent::__construct([
            self::ROOT => Annotation::withReducer(
                MessagesReducer::messagesStateReducer(...),
                static fn (): array => [],
            ),
        ]);
    }
}
