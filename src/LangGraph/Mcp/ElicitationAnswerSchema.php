<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

/**
 * The parser `elicitationAnswerFor(request)` returns: an MCP `ElicitResult` checked against the
 * question that was asked.
 *
 * Two flavours, as upstream. The SDK's `ElicitResultSchema` (`$modern = false`) is loose and keeps
 * unknown keys; the adapter's `modernElicitationAnswerSchema` (`$modern = true`) is
 * `ElicitResultSchema.pick({action, content}).strip()` and keeps only those two.
 *
 * Beyond the envelope, an `accept` answer to a form is validated against the form's
 * `requestedSchema` and an answer to a URL question may not carry form content.
 */
final class ElicitationAnswerSchema
{
    private const ACTIONS = ['accept', 'decline', 'cancel'];

    /**
     * @param array<string, mixed> $request a form or URL elicitation question
     */
    public function __construct(private readonly array $request, private readonly bool $modern = false)
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function parse(mixed $input): array
    {
        $issues = $this->issues($input);
        if ($issues !== []) {
            throw new ValidationException($issues);
        }

        /** @var array<string, mixed> $input */
        return $this->modern
            ? array_intersect_key($input, ['action' => true, 'content' => true])
            : $input;
    }

    /**
     * @return array{success: true, data: array<string, mixed>}|array{success: false, error: ValidationException}
     */
    public function safeParse(mixed $input): array
    {
        try {
            return ['success' => true, 'data' => $this->parse($input)];
        } catch (ValidationException $e) {
            return ['success' => false, 'error' => $e];
        }
    }

    /**
     * @return list<array{path: list<int|string>, message: string}>
     */
    public function issues(mixed $input): array
    {
        if (!is_array($input) || (array_is_list($input) && $input !== [])) {
            return [['path' => [], 'message' => 'Invalid input: expected object, received ' . Hooks::describe($input)]];
        }

        $issues = [];
        $action = $input['action'] ?? null;
        if (!in_array($action, self::ACTIONS, true)) {
            $issues[] = ['path' => ['action'], 'message' => 'Invalid option: expected one of "accept"|"decline"|"cancel"'];
        }

        $content = $input['content'] ?? null;
        if ($content !== null && !self::isFormContent($content)) {
            $issues[] = ['path' => ['content'], 'message' => 'Invalid input: expected record of string, number, boolean or string[]'];
        }
        if ($issues !== []) {
            return $issues;
        }

        if (($this->request['mode'] ?? null) === 'url') {
            if ($content !== null) {
                $issues[] = ['path' => ['content'], 'message' => 'URL elicitation answers cannot contain form content'];
            }
        } elseif ($action === 'accept') {
            // Collapse the instance path: the SDK validator reports it inside the message
            // (`data/confirm must be boolean`) and files every issue under `content`.
            foreach (JsonSchemaValidator::validate($content ?? [], $this->request['requestedSchema'] ?? true) as $issue) {
                $issues[] = ['path' => ['content'], 'message' => $issue['message']];
            }
        }

        return $issues;
    }

    private static function isFormContent(mixed $content): bool
    {
        if (!is_array($content) || (array_is_list($content) && $content !== [])) {
            return false;
        }
        foreach ($content as $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (!is_string($item)) {
                        return false;
                    }
                }
            } elseif (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                return false;
            }
        }

        return true;
    }
}
