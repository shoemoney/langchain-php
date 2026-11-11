<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels;

use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\StringPromptValue;
use LangChain\Tracers\CallbackHandler;
use LangChain\Utils\Testing\FakeListChatModel;
use PHPUnit\Framework\TestCase;

final class BaseChatModelCallbackCarrierTest extends TestCase
{
    public function testInvokeAndBothGenerationEntryPointsDeliverOneEventPerCarriedHandler(): void
    {
        $starts = [];
        $handler = CallbackHandler::fromMethods(['handleChatModelStart' => static function () use (&$starts): void {
            $starts[] = 'start';
        }]);
        $config = new RunnableConfig(callbacks: [$handler], runName: 'model call');
        $model = new FakeListChatModel(['responses' => ['ok']]);
        $model->invoke('question', $config);
        $model->generatePrompt([new StringPromptValue('question')], [], [$handler], $config);
        $model->generateMessages([[new HumanMessage('question')]], [], [$handler], $config);
        self::assertSame(['start', 'start', 'start'], $starts);
        self::assertSame([$handler], $config->callbacks);
    }

    public function testStandaloneCarrierFillsEmptyConfigAndExplicitDuplicatesStayOrdered(): void
    {
        $starts = [];
        $first = CallbackHandler::fromMethods(['handleChatModelStart' => static function () use (&$starts): void { $starts[] = 'first'; }]);
        $second = CallbackHandler::fromMethods(['handleChatModelStart' => static function () use (&$starts): void { $starts[] = 'second'; }]);
        $model = new FakeListChatModel(['responses' => ['ok']]);
        $model->generatePrompt([new StringPromptValue('question')], [], [$first], new RunnableConfig(tags: ['call']));
        $model->invoke('question', RunnableConfig::mergeConfigs(['callbacks' => [$first]], ['callbacks' => [$first, $second]]));
        self::assertSame(['first', 'first', 'first', 'second'], $starts);
    }

    public function testFullConfigCallbackCarrierHasPrecedenceOverSeparateArgument(): void
    {
        $starts = [];
        $first = CallbackHandler::fromMethods(['handleChatModelStart' => static function () use (&$starts): void { $starts[] = 'argument'; }]);
        $second = CallbackHandler::fromMethods(['handleChatModelStart' => static function () use (&$starts): void { $starts[] = 'config'; }]);
        (new FakeListChatModel(['responses' => ['ok']]))->generateMessages([[new HumanMessage('question')]], [], [$first],
            new RunnableConfig(callbacks: [$second]));
        self::assertSame(['config'], $starts);
    }
}
