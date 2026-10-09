<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Runtime;
use LangGraph\Pregel\Utils\Config;

/**
 * Emulates tools with an LLM instead of executing them.
 *
 * Port of `toolEmulatorMiddleware` from `langchain/src/agents/middleware/toolEmulator.ts`.
 *
 * With no `tools` (or an empty list) every tool is emulated; otherwise only the tools named (or passed as
 * instances) are, and the rest run normally. The emulation call is tagged
 * {@see Constants::INTERNAL_CALL_TAG} so it stays out of the messages stream.
 *
 * `model` is a chat model instance, or a "provider:model" string resolved lazily on the first emulated call
 * (upstream defaults the documented string to `anthropic:claude-sonnet-4-5-20250929`) resolved through
 * {@see \LangChain\LanguageModels\Chat\Universal\InitChatModel::init()} with `temperature` 1; without it the agent's own model does the emulating. When
 * the string cannot be resolved the error is logged and the agent model is used instead, as upstream does.
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $model,
 *     'tools' => [$getWeather, $calculator],
 *     'middleware' => [ToolEmulatorMiddleware::create(['tools' => ['get_weather']])],
 * ]);
 * ```
 */
final class ToolEmulatorMiddleware
{
    private function __construct()
    {
    }

    /**
     * @param array{tools?: list<mixed>|null, model?: string|RunnableInterface|null} $options
     * @return array<string, mixed> the middleware
     */
    public static function create(array $options = []): array
    {
        $tools = $options['tools'] ?? null;
        $model = $options['model'] ?? null;

        $emulateAll = $tools === null || $tools === [];
        $toolsToEmulate = [];
        if (!$emulateAll) {
            foreach ($tools as $tool) {
                $toolsToEmulate[\is_string($tool) ? $tool : self::nameOf($tool)] = true;
            }
        }

        $agentModel = null;
        $emulatorModel = null;

        $getEmulatorModel = static function () use ($model, &$agentModel, &$emulatorModel): RunnableInterface {
            if ($model instanceof RunnableInterface) {
                return $model;
            }
            if (\is_string($model)) {
                $emulatorModel ??= self::initEmulatorModel($model, $agentModel);

                return $emulatorModel;
            }

            return $agentModel ?? throw new \RuntimeException('ToolEmulatorMiddleware has no model to emulate with: pass `model`, or use it in an agent so the agent model is available.');
        };

        return Middleware::create([
            'name' => 'ToolEmulatorMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use (&$agentModel): mixed {
                $agentModel = $request['model'] ?? null;

                return $handler($request);
            },
            'wrapToolCall' => static function (array $request, callable $handler) use ($emulateAll, $toolsToEmulate, $getEmulatorModel): mixed {
                $toolName = (string) ($request['toolCall']['name'] ?? '');

                if (!$emulateAll && !isset($toolsToEmulate[$toolName])) {
                    return $handler($request);
                }

                $toolArgs = $request['toolCall']['args'] ?? null;
                $tool = $request['tool'] ?? null;
                $toolDescription = \is_object($tool) && property_exists($tool, 'description') && \is_string($tool->description) && $tool->description !== ''
                    ? $tool->description
                    : 'No description available';

                // `JSON.stringify({})`, not `[]`, for no arguments.
                $toolArgsString = \is_string($toolArgs)
                    ? $toolArgs
                    : (string) json_encode($toolArgs === [] || $toolArgs === null ? new \stdClass() : $toolArgs, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

                $prompt = "You are emulating a tool call for testing purposes.\n\n"
                    . "Tool: {$toolName}\n"
                    . "Description: {$toolDescription}\n"
                    . "Arguments: {$toolArgsString}\n\n"
                    . 'Generate a realistic response that this tool would return given these arguments.'
                    . "\nReturn ONLY the tool's output, no explanation or preamble. Introduce variation into your responses.";

                $emulator = $getEmulatorModel();
                $config = self::baseConfig($request['runtime'] ?? null);
                $config->tags = array_values(array_unique([...$config->tags, Constants::INTERNAL_CALL_TAG]));
                $config->metadata = [...$config->metadata, 'lc_source' => 'toolEmulation'];
                $response = $emulator->invoke([new HumanMessage($prompt)], $config);

                $content = $response->content;

                // Short-circuit: return the emulated result without executing the real tool.
                return new ToolMessage([
                    'content' => \is_string($content) ? $content : (string) json_encode($content, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => (string) ($request['toolCall']['id'] ?? ''),
                    'name' => $toolName,
                ]);
            },
        ]);
    }

    /**
     * Upstream's `pickRunnableConfigKeys(request.runtime)`: the run's tags, metadata, callbacks and signal, so the
     * emulation call belongs to the same trace and stream as the tool call it replaces.
     */
    private static function baseConfig(mixed $runtime): RunnableConfig
    {
        $ambient = Config::getConfig();
        if ($ambient !== null) {
            return new RunnableConfig(
                tags: $ambient->tags,
                metadata: $ambient->metadata,
                callbacks: $ambient->callbacks,
                recursionLimit: $ambient->recursionLimit,
                signal: $ambient->signal,
                runName: $ambient->runName,
                configurable: $ambient->configurable,
                context: $ambient->context,
            );
        }

        return $runtime instanceof Runtime
            ? new RunnableConfig(signal: $runtime->signal, configurable: $runtime->configurable, context: $runtime->context)
            : new RunnableConfig();
    }

    private static function initEmulatorModel(string $model, ?RunnableInterface $agentModel): RunnableInterface
    {
        try {
            $resolved = \LangChain\LanguageModels\Chat\Universal\InitChatModel::init($model, ['temperature' => 1]);
        } catch (\Throwable $error) {
            error_log('Error initializing emulator model, using agent model: ' . $error->getMessage());
            $resolved = null;
        }

        return $resolved instanceof RunnableInterface
            ? $resolved
            : ($agentModel ?? throw new \RuntimeException('The emulator model could not be initialized and there is no agent model to fall back to.'));
    }

    private static function nameOf(mixed $tool): string
    {
        if (\is_object($tool) && property_exists($tool, 'name')) {
            return (string) $tool->name;
        }
        if (\is_object($tool) && method_exists($tool, 'getName')) {
            return (string) $tool->getName();
        }

        return \is_array($tool) ? (string) ($tool['name'] ?? '') : '';
    }
}
