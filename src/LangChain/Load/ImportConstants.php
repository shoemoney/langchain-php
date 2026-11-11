<?php

declare(strict_types=1);

namespace LangChain\Load;

/** Complete emitted-identifier inventory for the pinned PHP tree; unavailable entries fail explicitly. */
final class ImportConstants
{
    /** Pinned core upstream import_constants.ts has no optional entrypoints. */
    public const OPTIONAL_IMPORT_ENTRYPOINTS = [];

    /** @return array<class-string, array{id: list<string>, status: string, reason: string}> */
    public static function inventory(): array
    {
        return [
            \LangChain\ChatHistory\InMemoryChatMessageHistory::class => [
                'id' => ['langchain', 'stores', 'message', 'in_memory', 'InMemoryChatMessageHistory'],
                'status' => 'gap',
                'reason' => 'N19b/N27: constructor does not capture its messages in Serializable kwargs; restoring empty kwargs loses accumulated history.',
            ],
            \LangChain\ExampleSelectors\LengthBasedExampleSelector::class => [
                'id' => ['langchain_core', 'example_selectors', 'base', 'LengthBasedExampleSelector'],
                'status' => 'upstream_not_implemented',
                'reason' => 'Upstream selectors are not serializable; PHP emits empty kwargs. N19b reconciles serialization.',
            ],
            \LangChain\ExampleSelectors\SemanticSimilarityExampleSelector::class => [
                'id' => ['langchain_core', 'example_selectors', 'base', 'SemanticSimilarityExampleSelector'],
                'status' => 'upstream_not_implemented',
                'reason' => 'Upstream selectors are not serializable; PHP emits empty kwargs. N19b reconciles serialization.',
            ],
            \LangChain\Indexing\InMemoryRecordManager::class => [
                'id' => ['langchain', 'recordmanagers', 'InMemoryRecordManager'],
                'status' => 'gap',
                'reason' => 'N19b/N27: PHP-only record manager emits empty kwargs; stored records, update times, group IDs and injected clock are absent.',
            ],
            \LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic::class => [
                'id' => ['langchain', 'chat_models', 'anthropic', 'ChatAnthropic'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\DeepSeek\ChatDeepSeek::class => [
                'id' => ['langchain', 'chat_models', 'deepseek', 'ChatDeepSeek'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\Fireworks\ChatFireworks::class => [
                'id' => ['langchain', 'chat_models', 'fireworks', 'ChatFireworks'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\Ollama\ChatOllama::class => [
                'id' => ['langchain', 'chat_models', 'ollama', 'ChatOllama'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed unauthenticated local-provider factory; Ollama needs no API key. Runtime HTTP client injection is unsupported.',
            ],
            \LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAI::class => [
                'id' => ['langchain', 'chat_models', 'azure_openai', 'AzureChatOpenAI'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAICompletions::class => [
                'id' => ['langchain', 'chat_models', 'azure_openai', 'AzureChatOpenAICompletions'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAIResponses::class => [
                'id' => ['langchain', 'chat_models', 'azure_openai', 'AzureChatOpenAIResponses'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI::class => [
                'id' => ['langchain', 'chat_models', 'openai', 'ChatOpenAI'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions::class => [
                'id' => ['langchain', 'chat_models', 'openai', 'ChatOpenAICompletions'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses::class => [
                'id' => ['langchain', 'chat_models', 'openai', 'ChatOpenAIResponses'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\OpenAI\Tools\DallEAPIWrapper::class => [
                'id' => ['langchain', 'tools', 'DallEAPIWrapper'],
                'status' => 'excluded',
                'reason' => 'Runtime tool/storage construction is excluded; callable, filesystem or stored state is not portable (N27).',
            ],
            \LangChain\LanguageModels\Chat\OpenRouter\ChatOpenRouter::class => [
                'id' => ['langchain', 'chat_models', 'openrouter', 'ChatOpenRouter'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\TogetherAI\ChatTogetherAI::class => [
                'id' => ['langchain', 'chat_models', 'together_ai', 'ChatTogetherAI'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\Universal\ConfigurableModel::class => [
                'id' => ['langchain', 'chat_models', 'ConfigurableModel'],
                'status' => 'gap',
                'reason' => 'N19b/N27: inherited kwargs omit defaultConfig, configurableFields, configPrefix, queuedMethodOperations and profile override; loading would discard model selection and queued bindings.',
            ],
            \LangChain\LanguageModels\Chat\XAI\ChatXAI::class => [
                'id' => ['langchain', 'chat_models', 'xai', 'ChatXAI'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\Chat\XAI\ChatXAIResponses::class => [
                'id' => ['langchain', 'chat_models', 'xai', 'ChatXAIResponses'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\LLMs\AzureOpenAI::class => [
                'id' => ['langchain', 'llms', 'openai', 'AzureOpenAI'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\LLMs\Fireworks::class => [
                'id' => ['langchain', 'llms', 'fireworks', 'Fireworks'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\LLMs\OpenAI::class => [
                'id' => ['langchain', 'llms', 'openai', 'OpenAI'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\LanguageModels\LLMs\TogetherAI::class => [
                'id' => ['langchain', 'llms', 'together_ai', 'TogetherAI'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed provider factory; API key must be explicit. Secret-marker emission remains N19b.',
            ],
            \LangChain\Messages\AIMessage::class => [
                'id' => ['langchain_core', 'messages', 'AIMessage'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\AIMessageChunk::class => [
                'id' => ['langchain_core', 'messages', 'AIMessageChunk'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\ChatMessage::class => [
                'id' => ['langchain_core', 'messages', 'ChatMessage'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\ChatMessageChunk::class => [
                'id' => ['langchain_core', 'messages', 'ChatMessageChunk'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\FunctionMessage::class => [
                'id' => ['langchain_core', 'messages', 'FunctionMessage'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\FunctionMessageChunk::class => [
                'id' => ['langchain_core', 'messages', 'FunctionMessageChunk'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\HumanMessage::class => [
                'id' => ['langchain_core', 'messages', 'HumanMessage'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\HumanMessageChunk::class => [
                'id' => ['langchain_core', 'messages', 'HumanMessageChunk'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\RemoveMessage::class => [
                'id' => ['langchain_core', 'messages', 'RemoveMessage'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\SystemMessage::class => [
                'id' => ['langchain_core', 'messages', 'SystemMessage'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\SystemMessageChunk::class => [
                'id' => ['langchain_core', 'messages', 'SystemMessageChunk'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\ToolMessage::class => [
                'id' => ['langchain_core', 'messages', 'ToolMessage'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Messages\ToolMessageChunk::class => [
                'id' => ['langchain_core', 'messages', 'ToolMessageChunk'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Prompts\AIMessagePromptTemplate::class => [
                'id' => ['langchain_core', 'prompts', 'chat', 'AIMessagePromptTemplate'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Prompts\ChatMessagePromptTemplate::class => [
                'id' => ['langchain_core', 'prompts', 'chat', 'ChatMessagePromptTemplate'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Prompts\ChatPromptTemplate::class => [
                'id' => ['langchain_core', 'prompts', 'chat', 'ChatPromptTemplate'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Prompts\DictPromptTemplate::class => [
                'id' => ['langchain_core', 'prompts', 'dict', 'DictPromptTemplate'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Prompts\FewShotChatMessagePromptTemplate::class => [
                'id' => ['langchain_core', 'prompts', 'few_shot_chat', 'FewShotChatMessagePromptTemplate'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Prompts\FewShotPromptTemplate::class => [
                'id' => ['langchain_core', 'prompts', 'few_shot', 'FewShotPromptTemplate'],
                'status' => 'upstream_not_implemented',
                'reason' => 'Upstream string few-shot prompt disables constructor JSON serialization.',
            ],
            \LangChain\Prompts\HumanMessagePromptTemplate::class => [
                'id' => ['langchain_core', 'prompts', 'chat', 'HumanMessagePromptTemplate'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Prompts\ImagePromptTemplate::class => [
                'id' => ['langchain_core', 'prompts', 'prompt', 'ImagePromptTemplate'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Prompts\MessagesPlaceholder::class => [
                'id' => ['langchain_core', 'prompts', 'chat', 'MessagesPlaceholder'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Prompts\PromptTemplate::class => [
                'id' => ['langchain_core', 'prompts', 'prompt', 'PromptTemplate'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Prompts\SystemMessagePromptTemplate::class => [
                'id' => ['langchain_core', 'prompts', 'chat', 'SystemMessagePromptTemplate'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Schema\ChatPromptValue::class => [
                'id' => ['langchain_core', 'prompt_values', 'ChatPromptValue'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Schema\Document::class => [
                'id' => ['langchain_core', 'documents', 'Document'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed baseline page_content/metadata factory; Document.id adapter needs the separate N06 dependency (N27).',
            ],
            \LangChain\Schema\StringPromptValue::class => [
                'id' => ['langchain_core', 'prompt_values', 'StringPromptValue'],
                'status' => 'supported_constructor',
                'reason' => 'Fixed core constructor factory; existing serializer omissions remain N19b.',
            ],
            \LangChain\Storage\EncoderBackedStore::class => [
                'id' => ['langchain', 'storage', 'EncoderBackedStore'],
                'status' => 'excluded',
                'reason' => 'Runtime tool/storage construction is excluded; callable, filesystem or stored state is not portable (N27).',
            ],
            \LangChain\Storage\InMemoryStore::class => [
                'id' => ['langchain', 'storage', 'InMemoryStore'],
                'status' => 'excluded',
                'reason' => 'Runtime tool/storage construction is excluded; callable, filesystem or stored state is not portable (N27).',
            ],
            \LangChain\Storage\LocalFileStore::class => [
                'id' => ['langchain', 'storage', 'LocalFileStore'],
                'status' => 'excluded',
                'reason' => 'Runtime tool/storage construction is excluded; callable, filesystem or stored state is not portable (N27).',
            ],
            \LangChain\Stores\InMemoryStore::class => [
                'id' => ['langchain', 'storage', 'InMemoryStore'],
                'status' => 'excluded',
                'reason' => 'Runtime tool/storage construction is excluded; callable, filesystem or stored state is not portable (N27).',
            ],
            \LangChain\Tools\DynamicStructuredTool::class => [
                'id' => ['langchain', 'tools', 'DynamicStructuredTool'],
                'status' => 'excluded',
                'reason' => 'Runtime tool/storage construction is excluded; callable, filesystem or stored state is not portable (N27).',
            ],
            \LangChain\Tools\DynamicTool::class => [
                'id' => ['langchain', 'tools', 'DynamicTool'],
                'status' => 'excluded',
                'reason' => 'Runtime tool/storage construction is excluded; callable, filesystem or stored state is not portable (N27).',
            ],
            \LangChain\Tracers\CallbackHandler::class => [
                'id' => ['langchain_core', 'callbacks', 'BaseCallbackHandler'],
                'status' => 'gap',
                'reason' => 'N19b/N27: several concrete callback classes emit the same base identifier; runtime callback state is not portable.',
            ],
            \LangChain\Tracers\ConsoleCallbackHandler::class => [
                'id' => ['langchain_core', 'callbacks', 'BaseCallbackHandler'],
                'status' => 'gap',
                'reason' => 'N19b/N27: several concrete callback classes emit the same base identifier; runtime callback state is not portable.',
            ],
            \LangChain\Tracers\EventStreamCallbackHandler::class => [
                'id' => ['langchain_core', 'callbacks', 'BaseCallbackHandler'],
                'status' => 'gap',
                'reason' => 'N19b/N27: several concrete callback classes emit the same base identifier; runtime callback state is not portable.',
            ],
            \LangChain\Tracers\LangChainTracer::class => [
                'id' => ['langchain_core', 'callbacks', 'BaseCallbackHandler'],
                'status' => 'gap',
                'reason' => 'N19b/N27: several concrete callback classes emit the same base identifier; runtime callback state is not portable.',
            ],
            \LangChain\Tracers\LogStreamCallbackHandler::class => [
                'id' => ['langchain_core', 'callbacks', 'BaseCallbackHandler'],
                'status' => 'gap',
                'reason' => 'N19b/N27: several concrete callback classes emit the same base identifier; runtime callback state is not portable.',
            ],
            \LangChain\Tracers\RunCollectorCallbackHandler::class => [
                'id' => ['langchain_core', 'callbacks', 'BaseCallbackHandler'],
                'status' => 'gap',
                'reason' => 'N19b/N27: several concrete callback classes emit the same base identifier; runtime callback state is not portable.',
            ],
            \LangChain\Utils\Testing\FakeChatModel::class => [
                'id' => ['langchain', 'chat_models', 'FakeChatModel'],
                'status' => 'gap',
                'reason' => 'N27: no fixed test-provider import factory is registered; this echo model has no scripted response state, so exclusion is a registry scope gap, not missing constructor data.',
            ],
            \LangChain\Utils\Testing\FakeLLM::class => [
                'id' => ['langchain', 'llms', 'FakeLLM'],
                'status' => 'gap',
                'reason' => 'N19b/N27: inherited kwargs omit the configured response and thrownErrorString; a loaded model would silently change echo/error behavior.',
            ],
            \LangChain\Utils\Testing\FakeListChatModel::class => [
                'id' => ['langchain', 'chat_models', 'fake-list', 'FakeListChatModel'],
                'status' => 'gap',
                'reason' => 'N27: kwargs include responses, sleep, emit_custom_event and generationInfo but omit the current replay cursor; no fixed test-provider factory is registered.',
            ],
            \LangChain\Utils\Testing\FakeStreamingChatModel::class => [
                'id' => ['langchain', 'chat_models', 'FakeStreamingChatModel'],
                'status' => 'gap',
                'reason' => 'N19b/N27: inherited kwargs omit responses, chunks, sleep and bound tool style/options, losing the configured streaming script.',
            ],
            \LangChain\Utils\Testing\FakeStreamingLLM::class => [
                'id' => ['langchain', 'llms', 'FakeStreamingLLM'],
                'status' => 'gap',
                'reason' => 'N19b/N27: inherited kwargs omit remaining responses, sleep and thrownErrorString, losing the configured completion stream/error script.',
            ],
            \LangChain\Utils\Testing\FakeTool::class => [
                'id' => ['langchain', 'tools', 'FakeTool'],
                'status' => 'gap',
                'reason' => 'N19b/N27: no fixed testing-tool factory; emitted tool kwargs do not encode the concrete Schema object or runtime response-format behavior.',
            ],
            \LangChain\Utils\Testing\FakeToolCallingChatModel::class => [
                'id' => ['langchain', 'chat_models', 'FakeToolCallingChatModel'],
                'status' => 'gap',
                'reason' => 'N19b/N27: inherited kwargs omit responses, sleep, toolStyle, structuredResponse and replay cursor, losing scripted tool calls and structured output.',
            ],
            \LangChain\Utils\Testing\FakeTracer::class => [
                'id' => ['langchain_core', 'callbacks', 'BaseCallbackHandler'],
                'status' => 'gap',
                'reason' => 'N19b/N27: several concrete callback classes emit the same base identifier; runtime callback state is not portable.',
            ],
            \LangChain\VectorStores\MemoryVectorStore::class => [
                'id' => ['langchain', 'vectorstores', 'MemoryVectorStore'],
                'status' => 'gap',
                'reason' => 'N19b/N27: Serializable kwargs omit the required embeddings implementation, memoryVectors corpus and similarity callable; an empty constructor cannot restore the store.',
            ],
            \LangChain\VectorStores\VectorStoreRetriever::class => [
                'id' => ['langchain_core', 'vectorstores', 'VectorStoreRetriever'],
                'status' => 'gap',
                'reason' => 'N19b/N27: inherited kwargs omit the required vectorStore object and k/filter/searchType/searchKwargs, so loading loses retrieval configuration and backend.',
            ],
        ];
    }

    /** @param list<string> $id */
    public static function unavailableReason(array $id): ?string
    {
        foreach (self::inventory() as $entry) {
            if ($entry['id'] === $id && $entry['status'] !== 'supported_constructor') {
                return $entry['reason'];
            }
        }

        return null;
    }
}
