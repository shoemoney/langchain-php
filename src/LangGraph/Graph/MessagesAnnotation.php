<?php

declare(strict_types=1);

namespace LangGraph\Graph;

use LangGraph\State\Annotation;
use LangGraph\State\AnnotationRoot;

/**
 * The prebuilt state schema for a chat: one `messages` key that accumulates.
 *
 * Port of `MessagesAnnotation` from `langgraph-core/src/graph/messages_annotation.ts`
 * (the Zod variants, `MessagesZodMeta` and `MessagesZodState`, have no port: the
 * schema here is JSON-Schema-native).
 *
 * `new StateGraph(MessagesAnnotation::root())` is equivalent to declaring
 * `messages` yourself with {@see MessagesReducer::messagesStateReducer()} as its
 * reducer and `[]` as its default.
 */
final class MessagesAnnotation
{
    private function __construct()
    {
    }

    public static function root(): AnnotationRoot
    {
        return Annotation::root([
            'messages' => Annotation::withReducer(
                MessagesReducer::messagesStateReducer(...),
                static fn (): array => [],
            ),
        ]);
    }
}
