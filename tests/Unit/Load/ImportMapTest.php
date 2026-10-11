<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Load;

use LangChain\Load\ImportConstants;
use LangChain\Load\ImportMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportConstants::class)]
#[CoversClass(ImportMap::class)]
final class ImportMapTest extends TestCase
{
    public function testEveryShippedEmittedIdentifierHasAnExplicitClassification(): void
    {
        $base = dirname(__DIR__, 3) . '/src/LangChain/';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'LangChain\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($base), -4));
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->hasMethod('lcId') || !$reflection->hasMethod('toJson') || !$reflection->getMethod('lcId')->isPublic()) {
                continue;
            }
            $found[$class] = $class::lcId();
        }
        $inventory = ImportConstants::inventory();
        self::assertEqualsCanonicalizing(array_keys($found), array_keys($inventory));
        foreach ($found as $class => $id) {
            self::assertSame($id, $inventory[$class]['id'], $class);
            self::assertContains($inventory[$class]['status'], ['supported_constructor', 'upstream_not_implemented', 'excluded', 'gap']);
            self::assertNotEmpty($inventory[$class]['reason']);
            if ($inventory[$class]['status'] === 'supported_constructor') {
                self::assertInstanceOf(\Closure::class, ImportMap::resolve($id));
            } else {
                self::assertNotNull(ImportConstants::unavailableReason($id));
                try {
                    ImportMap::resolve($id);
                    self::fail('Unavailable identifier must fail honestly.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('Unsupported serialized constructor', $exception->getMessage());
                }
            }
        }
        self::assertSame([], ImportConstants::OPTIONAL_IMPORT_ENTRYPOINTS);
    }

    public function testLongestMatchingNamespaceDoesNotFallBackAfterAClassMiss(): void
    {
        self::assertInstanceOf(\Closure::class, ImportMap::resolve(['langchain_core', 'messages', 'ai', 'AIMessage']));
        self::assertInstanceOf(\Closure::class, ImportMap::resolve(['langchain_core', 'messages', 'tool', 'ToolMessage']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid identifier AIMessage');
        ImportMap::resolve(['langchain_core', 'messages', 'tool', 'AIMessage']);
    }

    public function testKeyMappingIsShallowAndUsesExplicitAliases(): void
    {
        $nested = ['keep_snake_key' => true];
        self::assertSame([
            'inputVariables' => ['name'], 'maxTokens' => 1, 'apiKey' => 'fake-key', 'URLField' => $nested,
        ], ImportMap::mapKeys(['input_variables' => ['name'], 'MAX-TOKENS' => 1, 'xai_api_key' => 'fake-key', 'URLField' => $nested], ['xai_api_key' => 'apiKey']));
    }

    public function testGapReasonsDescribeConcreteStateOrRegistryScope(): void
    {
        $inventory = ImportConstants::inventory();
        foreach ($inventory as $entry) {
            self::assertStringNotContainsString('constructor state or emitted identifier', $entry['reason']);
        }
        self::assertStringContainsString('messages', $inventory[\LangChain\ChatHistory\InMemoryChatMessageHistory::class]['reason']);
        self::assertStringContainsString('clock', $inventory[\LangChain\Indexing\InMemoryRecordManager::class]['reason']);
        self::assertStringContainsString('defaultConfig', $inventory[\LangChain\LanguageModels\Chat\Universal\ConfigurableModel::class]['reason']);
        self::assertStringContainsString('no scripted response state', $inventory[\LangChain\Utils\Testing\FakeChatModel::class]['reason']);
        self::assertStringContainsString('needs no API key', $inventory[\LangChain\LanguageModels\Chat\Ollama\ChatOllama::class]['reason']);
    }
}
