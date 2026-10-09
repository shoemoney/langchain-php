<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Tests\Unit\Agents\Support\FakeConfigurableModel;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingChatModel;
use LangGraph\Agents\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain/src/agents/tests/model.test.ts`. Upstream constructs a real `ConfigurableModel`
 * (`initChatModel`, a later work package); a fake implementing the same interface stands in.
 */
#[CoversClass(Model::class)]
final class ModelTest extends TestCase
{
    public function testShouldReturnTrueForAConfigurableModelInstance(): void
    {
        self::assertTrue(Model::isConfigurableModel(new FakeConfigurableModel(['model' => new FakeToolCallingChatModel()])));
    }

    public function testShouldReturnFalseForAPlainObject(): void
    {
        self::assertFalse(Model::isConfigurableModel(new \stdClass()));
    }

    public function testShouldReturnFalseForNull(): void
    {
        self::assertFalse(Model::isConfigurableModel(null));
    }

    public function testShouldReturnFalseForUndefined(): void
    {
        // PHP has no `undefined`; the closest input is an absent value of a non-object type.
        self::assertFalse(Model::isConfigurableModel(false));
    }

    public function testShouldReturnFalseForAnObjectMissingRequiredProperties(): void
    {
        // Upstream: `_queuedMethodOperations` without `_getModelInstance`.
        $model = new \stdClass();
        $model->queuedMethodOperations = [];

        self::assertFalse(Model::isConfigurableModel($model));
    }

    public function testIsBaseChatModelIsTrueForChatModelsOnly(): void
    {
        self::assertTrue(Model::isBaseChatModel(new FakeToolCallingChatModel()));
        self::assertTrue(Model::isBaseChatModel(new FakeConfigurableModel(['model' => new FakeToolCallingChatModel()])));
        self::assertFalse(Model::isBaseChatModel(new \stdClass()));
        self::assertFalse(Model::isBaseChatModel(null));
    }

    public function testShouldReturnTrueForTheRealConfigurableModel(): void
    {
        $model = new \LangChain\LanguageModels\Chat\Universal\ConfigurableModel(['defaultConfig' => ['model' => 'gpt-4o', 'apiKey' => 'x']]);

        self::assertTrue(Model::isConfigurableModel($model));
        self::assertTrue(Model::isBaseChatModel($model));
    }

    public function testARealConfigurableModelRunsInAChainAfterBeingRecognised(): void
    {
        $http = \LangChain\Tests\Unit\LanguageModels\Chat\Universal\UniversalFixtures::openAiHttp('pong');
        $model = \LangChain\LanguageModels\Chat\Universal\InitChatModel::init('gpt-4o-mini', [
            'modelProvider' => 'openai',
            'apiKey' => 'sk-test',
            'httpClient' => $http,
        ]);
        self::assertTrue(Model::isConfigurableModel($model));

        $chain = $model->pipe(new \LangChain\OutputParsers\StringOutputParser());

        self::assertSame('pong', $chain->invoke('ping'));
    }
}
