<?php

declare(strict_types=1);

namespace LangChain\Load;

use LangChain\Messages;
use LangChain\Prompts;
use LangChain\Schema;
use LangChain\LanguageModels\Chat;
use LangChain\LanguageModels\LLMs;

/** Explicit constructor adapters; no class name or import path is evaluated from a manifest. */
final class ImportMap
{
    /** @return array<string, array<string, \Closure(array): object>> */
    public static function core(): array
    {
        $map = [
            'langchain_core/messages' => [
                'HumanMessage' => static fn (array $f): object => new Messages\HumanMessage($f),
                'AIMessage' => static fn (array $f): object => new Messages\AIMessage($f),
                'SystemMessage' => static fn (array $f): object => new Messages\SystemMessage($f),
                'ToolMessage' => static fn (array $f): object => new Messages\ToolMessage($f),
                'FunctionMessage' => static fn (array $f): object => new Messages\FunctionMessage($f),
                'ChatMessage' => static fn (array $f): object => new Messages\ChatMessage($f),
                'RemoveMessage' => static fn (array $f): object => new Messages\RemoveMessage($f),
                'HumanMessageChunk' => static fn (array $f): object => new Messages\HumanMessageChunk($f),
                'AIMessageChunk' => static fn (array $f): object => new Messages\AIMessageChunk($f),
                'SystemMessageChunk' => static fn (array $f): object => new Messages\SystemMessageChunk($f),
                'ToolMessageChunk' => static fn (array $f): object => new Messages\ToolMessageChunk($f),
                'FunctionMessageChunk' => static fn (array $f): object => new Messages\FunctionMessageChunk($f),
                'ChatMessageChunk' => static fn (array $f): object => new Messages\ChatMessageChunk($f),
            ],
            'langchain_core/documents' => [
                'Document' => static function (array $f): object {
                    $f = self::mapKeys($f);
                    if (array_key_exists('id', $f)) {
                        throw new \RuntimeException('Document.id constructor adapter requires the separate document identity package (N27).');
                    }

                    return new Schema\Document($f['pageContent'] ?? '', $f['metadata'] ?? []);
                },
            ],
            'langchain_core/prompt_values' => [
                'StringPromptValue' => static fn (array $f): object => new Schema\StringPromptValue($f['text']),
                'ChatPromptValue' => static fn (array $f): object => new Schema\ChatPromptValue(array_map(static function (mixed $message): Messages\BaseMessage {
                    if ($message instanceof Messages\BaseMessage) {
                        return $message;
                    }
                    if (is_array($message) && isset($message['type'], $message['data']) && is_array($message['data'])) {
                        return Messages\MessageUtils::constructFromParams(['type' => $message['type']] + $message['data']);
                    }

                    throw new \InvalidArgumentException('ChatPromptValue requires serialized message objects or message dictionaries.');
                }, $f['messages'])),
            ],
            'langchain_core/prompts' => [
                'PromptTemplate' => static fn (array $f): object => self::promptTemplate(self::mapKeys($f)),
                'ImagePromptTemplate' => static function (array $f): object {
                    $f = self::mapKeys($f);

                    return new Prompts\ImagePromptTemplate($f['template'], $f['inputVariables'] ?? [], $f['partialVariables'] ?? [], $f['outputParser'] ?? null, $f['templateFormat'] ?? null, $f['validateTemplate'] ?? null, $f['additionalContentFields'] ?? null);
                },
                'DictPromptTemplate' => static function (array $f): object {
                    $f = self::mapKeys($f);

                    return new Prompts\DictPromptTemplate($f['template'], $f['templateFormat'] ?? null);
                },
                'HumanMessagePromptTemplate' => static fn (array $f): object => new Prompts\HumanMessagePromptTemplate($f['prompt'], $f['additional_options'] ?? $f['additionalOptions'] ?? []),
                'AIMessagePromptTemplate' => static fn (array $f): object => new Prompts\AIMessagePromptTemplate($f['prompt'], $f['additional_options'] ?? $f['additionalOptions'] ?? []),
                'SystemMessagePromptTemplate' => static fn (array $f): object => new Prompts\SystemMessagePromptTemplate($f['prompt'], $f['additional_options'] ?? $f['additionalOptions'] ?? []),
                'ChatMessagePromptTemplate' => static fn (array $f): object => new Prompts\ChatMessagePromptTemplate($f['prompt'], $f['role'] ?? 'chat'),
                'MessagesPlaceholder' => static function (array $f): object {
                    $f = self::mapKeys($f);

                    return new Prompts\MessagesPlaceholder($f['variableName'], $f['optional'] ?? false);
                },
                'ChatPromptTemplate' => static function (array $f): object {
                    $f = self::mapKeys($f);

                    return new Prompts\ChatPromptTemplate($f['promptMessages'] ?? $f['messages'] ?? [], $f['inputVariables'] ?? [], $f['partialVariables'] ?? [], $f['outputParser'] ?? null, $f['validateTemplate'] ?? null, $f['templateFormat'] ?? null, $f['metadata'] ?? [], $f['tags'] ?? []);
                },
                'FewShotChatMessagePromptTemplate' => static function (array $f): object {
                    $f = self::mapKeys($f);

                    return new Prompts\FewShotChatMessagePromptTemplate($f['examplePrompt'], $f['examples'] ?? null, $f['exampleSelector'] ?? null, $f['inputVariables'] ?? [], $f['partialVariables'] ?? [], $f['prefix'] ?? '', $f['suffix'] ?? '', $f['exampleSeparator'] ?? "\n\n", $f['templateFormat'] ?? 'f-string', $f['validateTemplate'] ?? true, $f['outputParser'] ?? null, $f['metadata'] ?? [], $f['tags'] ?? []);
                },
            ],
        ];
        $map['langchain_core/messages/tool'] = [
            'ToolMessage' => $map['langchain_core/messages']['ToolMessage'],
            'ToolMessageChunk' => $map['langchain_core/messages']['ToolMessageChunk'],
        ];

        return $map;
    }

    /** Bundled provider constructors, scoped by their emitted namespace.
     * @return array<string, array<string, \Closure(array): object>>
     */
    public static function providers(): array
    {
        return [
            'langchain/chat_models/openai' => [
                'ChatOpenAI' => static fn (array $f): object => new Chat\OpenAI\ChatOpenAI(self::providerFields($f, ['openai_api_key' => 'apiKey', 'open_ai_api_key' => 'apiKey'])),
                'ChatOpenAICompletions' => static fn (array $f): object => new Chat\OpenAI\ChatOpenAICompletions(self::providerFields($f, ['openai_api_key' => 'apiKey', 'open_ai_api_key' => 'apiKey'])),
                'ChatOpenAIResponses' => static fn (array $f): object => new Chat\OpenAI\ChatOpenAIResponses(self::providerFields($f, ['openai_api_key' => 'apiKey', 'open_ai_api_key' => 'apiKey'])),
            ],
            'langchain/chat_models/azure_openai' => [
                'AzureChatOpenAI' => static fn (array $f): object => new Chat\OpenAI\Azure\AzureChatOpenAI(self::providerFields($f, ['azure_openai_api_key' => 'azureOpenAIApiKey', 'openai_api_key' => 'apiKey'])),
                'AzureChatOpenAICompletions' => static fn (array $f): object => new Chat\OpenAI\Azure\AzureChatOpenAICompletions(self::providerFields($f, ['azure_openai_api_key' => 'azureOpenAIApiKey', 'openai_api_key' => 'apiKey'])),
                'AzureChatOpenAIResponses' => static fn (array $f): object => new Chat\OpenAI\Azure\AzureChatOpenAIResponses(self::providerFields($f, ['azure_openai_api_key' => 'azureOpenAIApiKey', 'openai_api_key' => 'apiKey'])),
            ],
            'langchain/chat_models/anthropic' => ['ChatAnthropic' => static fn (array $f): object => new Chat\Anthropic\ChatAnthropic(self::providerFields($f, ['anthropic_api_key' => 'apiKey']))],
            'langchain/chat_models/ollama' => ['ChatOllama' => static fn (array $f): object => new Chat\Ollama\ChatOllama(self::mapKeys($f))],
            'langchain/chat_models/openrouter' => ['ChatOpenRouter' => static fn (array $f): object => new Chat\OpenRouter\ChatOpenRouter(self::providerFields($f, ['openrouter_api_key' => 'apiKey']))],
            'langchain/chat_models/xai' => [
                'ChatXAI' => static fn (array $f): object => new Chat\XAI\ChatXAI(self::providerFields($f, ['xai_api_key' => 'apiKey'])),
                'ChatXAIResponses' => static fn (array $f): object => new Chat\XAI\ChatXAIResponses(self::providerFields($f, ['xai_api_key' => 'apiKey'])),
            ],
            'langchain/chat_models/together_ai' => ['ChatTogetherAI' => static fn (array $f): object => new Chat\TogetherAI\ChatTogetherAI(self::providerFields($f, ['together_ai_api_key' => 'apiKey']))],
            'langchain/chat_models/fireworks' => ['ChatFireworks' => static fn (array $f): object => new Chat\Fireworks\ChatFireworks(self::providerFields($f, ['fireworks_api_key' => 'apiKey']))],
            'langchain/chat_models/deepseek' => ['ChatDeepSeek' => static fn (array $f): object => new Chat\DeepSeek\ChatDeepSeek(self::providerFields($f, ['deepseek_api_key' => 'apiKey']))],
            'langchain/llms/openai' => [
                'OpenAI' => static fn (array $f): object => new LLMs\OpenAI(self::providerFields($f, ['openai_api_key' => 'apiKey', 'open_ai_api_key' => 'apiKey'])),
                'AzureOpenAI' => static fn (array $f): object => new LLMs\AzureOpenAI(self::providerFields($f, ['azure_openai_api_key' => 'azureOpenAIApiKey', 'openai_api_key' => 'apiKey'])),
            ],
            'langchain/llms/together_ai' => ['TogetherAI' => static fn (array $f): object => new LLMs\TogetherAI(self::providerFields($f, ['together_ai_api_key' => 'apiKey']))],
            'langchain/llms/fireworks' => ['Fireworks' => static fn (array $f): object => new LLMs\Fireworks(self::providerFields($f, ['fireworks_api_key' => 'apiKey']))],
        ];
    }

    /** @param list<string> $id
     * @return \Closure(array): object
     */
    public static function resolve(array $id): \Closure
    {
        $reason = ImportConstants::unavailableReason($id);
        if ($reason !== null) {
            throw new \RuntimeException('Unsupported serialized constructor ' . implode('/', $id) . ': ' . $reason);
        }
        $name = array_pop($id);
        $maps = self::core() + self::providers();
        while ($id !== []) {
            $namespace = implode('/', $id);
            if (isset($maps[$namespace])) {
                if (!isset($maps[$namespace][$name])) {
                    throw new \RuntimeException("Invalid identifier {$name} in {$namespace}.");
                }

                return $maps[$namespace][$name];
            }
            array_pop($id);
        }

        throw new \RuntimeException('Invalid namespace for serialized constructor.');
    }

    /** Map only constructor fields, never nested user data.
     * @param array<string, string> $aliases serialized spelling => PHP field
     */
    public static function mapKeys(array $fields, array $aliases = []): array
    {
        $result = [];
        foreach ($fields as $key => $value) {
            $mapped = $aliases[$key] ?? trim((string) $key);
            if (!isset($aliases[$key]) && preg_match('/[_\-\s]/', $mapped)) {
                $mapped = strtolower(preg_replace('/[_\-\s]+/', '_', $mapped));
                $mapped = preg_replace_callback('/_+([a-z0-9])/', static fn (array $m): string => strtoupper($m[1]), $mapped);
            }
            $result[$mapped] = $value;
        }

        // JSON may encode PHP's callable strings/arrays. These hooks are runtime injection
        // points, never serialized constructor state, even when a provider currently ignores one.
        foreach (array_keys($result) as $key) {
            if (in_array(strtolower($key), ['sleeper', 'azureadtokenprovider', 'backoffhandler', 'httpclient', 'asynccaller', 'callbacks', 'callbackmanager'], true)) {
                throw new \InvalidArgumentException("Runtime-only constructor field {$key} is not supported by the load registry.");
            }
        }
        if (array_key_exists('partialVariables', $result)) {
            if (!is_array($result['partialVariables'])) {
                throw new \InvalidArgumentException('Serialized partialVariables must be an array of strings.');
            }
            foreach ($result['partialVariables'] as $partial) {
                if (!is_string($partial)) {
                    throw new \InvalidArgumentException('Serialized partialVariables values must be strings; callable and non-string partials are unsupported.');
                }
            }
        }

        return $result;
    }

    private static function providerFields(array $fields, array $aliases): array
    {
        if (isset($aliases['azure_openai_api_key'])) {
            $aliases += [
                'azure_open_ai_api_key' => 'azureOpenAIApiKey',
                'azure_open_ai_api_instance_name' => 'azureOpenAIApiInstanceName',
                'openai_api_version' => 'azureOpenAIApiVersion',
                'openai_api_base' => 'azureOpenAIBasePath',
                'azure_endpoint' => 'azureOpenAIEndpoint',
                'deployment_name' => 'azureOpenAIApiDeploymentName',
            ];
        }
        $fields = self::mapKeys($fields, $aliases);
        $fields['apiKey'] ??= $fields['azureOpenAIApiKey'] ?? null;
        if (!is_string($fields['apiKey']) || $fields['apiKey'] === '') {
            throw new \RuntimeException('Provider loading requires an explicit API key or serialized secret supplied through secretsMap.');
        }

        return $fields;
    }

    private static function promptTemplate(array $f): Prompts\PromptTemplate
    {
        return new Prompts\PromptTemplate($f['template'], $f['inputVariables'] ?? [], $f['partialVariables'] ?? [], $f['outputParser'] ?? null, $f['templateFormat'] ?? null, $f['validateTemplate'] ?? null, $f['additionalContentFields'] ?? null, $f['metadata'] ?? [], $f['tags'] ?? []);
    }
}
