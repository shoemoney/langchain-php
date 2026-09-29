<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prompts;

use LangChain\Messages\AIMessage;
use LangChain\Messages\ChatMessage;
use LangChain\Messages\FunctionMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Prompts\AIMessagePromptTemplate;
use LangChain\Prompts\ChatMessagePromptTemplate;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Prompts\HumanMessagePromptTemplate;
use LangChain\Prompts\InputFormatError;
use LangChain\Prompts\MessagesPlaceholder;
use LangChain\Prompts\PromptInputError;
use LangChain\Prompts\PromptTemplate;
use LangChain\Prompts\SystemMessagePromptTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `prompts/tests/chat.test.ts`.
 */
#[CoversClass(ChatPromptTemplate::class)]
#[CoversClass(MessagesPlaceholder::class)]
#[CoversClass(HumanMessagePromptTemplate::class)]
#[CoversClass(AIMessagePromptTemplate::class)]
#[CoversClass(SystemMessagePromptTemplate::class)]
#[CoversClass(ChatMessagePromptTemplate::class)]
#[CoversClass(InputFormatError::class)]
#[CoversClass(PromptInputError::class)]
final class ChatPromptTemplateTest extends TestCase
{
    private static function chatPromptTemplate(): ChatPromptTemplate
    {
        $systemPrompt = new PromptTemplate(
            template: "Here's some context: {context}",
            inputVariables: ['context'],
        );
        $userPrompt = new PromptTemplate(
            template: "Hello {foo}, I'm {bar}. Thanks for the {context}",
            inputVariables: ['foo', 'bar', 'context'],
        );
        $aiPrompt = new PromptTemplate(
            template: "I'm an AI. I'm {foo}. I'm {bar}.",
            inputVariables: ['foo', 'bar'],
        );
        $genericPrompt = new PromptTemplate(
            template: "I'm a generic message. I'm {foo}. I'm {bar}.",
            inputVariables: ['foo', 'bar'],
        );

        return ChatPromptTemplate::fromMessages([
            new SystemMessagePromptTemplate($systemPrompt),
            new HumanMessagePromptTemplate($userPrompt),
            new AIMessagePromptTemplate($aiPrompt),
            new ChatMessagePromptTemplate($genericPrompt, 'test'),
        ]);
    }

    /**
     * @return list<array{0: \LangChain\Messages\BaseMessage, 1: string}>
     */
    private static function asPairs(array $messages): array
    {
        $pairs = [];
        foreach ($messages as $message) {
            $pairs[] = [$message, $message->text()];
        }

        return $pairs;
    }

    public function testFormat(): void
    {
        $chatPrompt = self::chatPromptTemplate();

        $value = $chatPrompt->formatPromptValue([
            'context' => 'This is a context',
            'foo' => 'Foo',
            'bar' => 'Bar',
            'unused' => 'extra',
        ]);

        self::assertEquals(self::asPairs([
            new SystemMessage("Here's some context: This is a context"),
            new HumanMessage("Hello Foo, I'm Bar. Thanks for the This is a context"),
            new AIMessage("I'm an AI. I'm Foo. I'm Bar."),
            new ChatMessage(['role' => 'test', 'content' => "I'm a generic message. I'm Foo. I'm Bar."]),
        ]), self::asPairs($value->toMessages()));
    }

    public function testFormatWithInvalidInputValues(): void
    {
        $chatPrompt = self::chatPromptTemplate();

        try {
            $chatPrompt->formatPromptValue([
                'context' => 'This is a context',
                'foo' => 'Foo',
            ]);
            self::fail('expected a PromptInputError');
        } catch (PromptInputError $e) {
            self::assertStringContainsString('Missing value for input variable `bar`', $e->getMessage());
            self::assertSame('INVALID_PROMPT_INPUT', $e->lcErrorCode);
        }
    }

    public function testFormatWithInvalidInputVariables(): void
    {
        $systemPrompt = new PromptTemplate(
            template: "Here's some context: {context}",
            inputVariables: ['context'],
        );
        $userPrompt = new PromptTemplate(
            template: "Hello {foo}, I'm {bar}",
            inputVariables: ['foo', 'bar'],
        );

        try {
            new ChatPromptTemplate(
                promptMessages: [
                    new SystemMessagePromptTemplate($systemPrompt),
                    new HumanMessagePromptTemplate($userPrompt),
                ],
                inputVariables: ['context', 'foo', 'bar', 'baz'],
            );
            self::fail('expected an unused-variable error');
        } catch (\RuntimeException $e) {
            self::assertSame(
                'Input variables `baz` are not used in any of the prompt messages.',
                $e->getMessage()
            );
        }

        try {
            new ChatPromptTemplate(
                promptMessages: [
                    new SystemMessagePromptTemplate($systemPrompt),
                    new HumanMessagePromptTemplate($userPrompt),
                ],
                inputVariables: ['context', 'foo'],
            );
            self::fail('expected an undeclared-variable error');
        } catch (\RuntimeException $e) {
            self::assertSame(
                'Input variables `bar` are used in prompt messages but not in the prompt template.',
                $e->getMessage()
            );
        }
    }

    public function testFromTemplate(): void
    {
        $chatPrompt = ChatPromptTemplate::fromTemplate("Hello {foo}, I'm {bar}");

        self::assertSame(['foo', 'bar'], $chatPrompt->inputVariables);

        $messages = $chatPrompt->formatPromptValue(['foo' => 'Foo', 'bar' => 'Bar'])->toMessages();

        self::assertEquals(
            self::asPairs([new HumanMessage('Hello Foo, I\'m Bar')]),
            self::asPairs($messages)
        );
    }

    public function testFromTemplateWithANonStringValue(): void
    {
        $chatPrompt = ChatPromptTemplate::fromTemplate("Hello {foo}, I'm {bar}");

        $value = $chatPrompt->invoke([
            'foo' => ['barbar'],
            'bar' => [['pageContent' => 'bar', 'metadata' => []]],
        ]);

        self::assertEquals(
            self::asPairs([new HumanMessage('Hello ["barbar"], I\'m [{"pageContent":"bar","metadata":[]}]')]),
            self::asPairs($value->toMessages())
        );
    }

    public function testFromMessages(): void
    {
        $systemPrompt = new PromptTemplate(
            template: "Here's some context: {context}",
            inputVariables: ['context'],
        );
        $userPrompt = new PromptTemplate(
            template: "Hello {foo}, I'm {bar}",
            inputVariables: ['foo', 'bar'],
        );

        $chatPrompt = ChatPromptTemplate::fromMessages([
            new SystemMessagePromptTemplate($systemPrompt),
            new HumanMessagePromptTemplate($userPrompt),
        ]);

        self::assertSame(['context', 'foo', 'bar'], $chatPrompt->inputVariables);

        $messages = $chatPrompt->formatPromptValue([
            'context' => 'This is a context',
            'foo' => 'Foo',
            'bar' => 'Bar',
        ])->toMessages();

        self::assertEquals(self::asPairs([
            new SystemMessage("Here's some context: This is a context"),
            new HumanMessage("Hello Foo, I'm Bar"),
        ]), self::asPairs($messages));
    }

    public function testFromMessagesWithNonStringInputs(): void
    {
        $systemPrompt = new PromptTemplate(
            template: "Here's some context: {context}",
            inputVariables: ['context'],
        );
        $userPrompt = new PromptTemplate(
            template: "Hello {foo}, I'm {bar}",
            inputVariables: ['foo', 'bar'],
        );

        $chatPrompt = ChatPromptTemplate::fromMessages([
            new SystemMessagePromptTemplate($systemPrompt),
            new HumanMessagePromptTemplate($userPrompt),
        ]);

        self::assertSame(['context', 'foo', 'bar'], $chatPrompt->inputVariables);

        $messages = $chatPrompt->formatPromptValue([
            'context' => [['pageContent' => 'bar', 'metadata' => []]],
            'foo' => 'Foo',
            'bar' => 'Bar',
        ])->toMessages();

        self::assertEquals(self::asPairs([
            new SystemMessage('Here\'s some context: [{"pageContent":"bar","metadata":[]}]'),
            new HumanMessage("Hello Foo, I'm Bar"),
        ]), self::asPairs($messages));
    }

    public function testFromMessagesWithAVarietyOfWaysToDeclarePromptMessages(): void
    {
        $systemPrompt = new PromptTemplate(
            template: "Here's some context: {context}",
            inputVariables: ['context'],
        );

        $chatPrompt = ChatPromptTemplate::fromMessages([
            new SystemMessagePromptTemplate($systemPrompt),
            "Hello {foo}, I'm {bar}",
            ['assistant', 'Nice to meet you, {bar}!'],
            ['human', 'Thanks {foo}!!'],
        ]);

        $messages = $chatPrompt->formatPromptValue([
            'context' => 'This is a context',
            'foo' => 'Foo',
            'bar' => 'Bar',
        ])->toMessages();

        self::assertEquals(self::asPairs([
            new SystemMessage("Here's some context: This is a context"),
            new HumanMessage("Hello Foo, I'm Bar"),
            new AIMessage('Nice to meet you, Bar!'),
            new HumanMessage('Thanks Foo!!'),
        ]), self::asPairs($messages));
    }

    public function testFromMessagesWithAnExtraInputVariable(): void
    {
        $systemPrompt = new PromptTemplate(
            template: "Here's some context: {context}",
            inputVariables: ['context'],
        );
        $userPrompt = new PromptTemplate(
            template: "Hello {foo}, I'm {bar}",
            inputVariables: ['foo', 'bar'],
        );

        $chatPrompt = ChatPromptTemplate::fromMessages([
            new SystemMessagePromptTemplate($systemPrompt),
            new HumanMessagePromptTemplate($userPrompt),
        ]);

        self::assertSame(['context', 'foo', 'bar'], $chatPrompt->inputVariables);

        $messages = $chatPrompt->formatPromptValue([
            'context' => 'This is a context',
            'foo' => 'Foo',
            'bar' => 'Bar',
            'unused' => 'No problemo!',
        ])->toMessages();

        self::assertCount(2, $messages);
    }

    public function testFromMessagesIsComposable(): void
    {
        $systemPrompt = new PromptTemplate(
            template: "Here's some context: {context}",
            inputVariables: ['context'],
        );
        $userPrompt = new PromptTemplate(
            template: "Hello {foo}, I'm {bar}",
            inputVariables: ['foo', 'bar'],
        );

        $chatPromptInner = ChatPromptTemplate::fromMessages([
            new SystemMessagePromptTemplate($systemPrompt),
            new HumanMessagePromptTemplate($userPrompt),
        ]);

        $chatPrompt = ChatPromptTemplate::fromMessages([
            $chatPromptInner,
            AIMessagePromptTemplate::fromTemplate("I'm an AI. I'm {foo}. I'm {bar}."),
        ]);

        self::assertSame(['context', 'foo', 'bar'], $chatPrompt->inputVariables);

        $messages = $chatPrompt->formatPromptValue([
            'context' => 'This is a context',
            'foo' => 'Foo',
            'bar' => 'Bar',
        ])->toMessages();

        self::assertEquals(self::asPairs([
            new SystemMessage("Here's some context: This is a context"),
            new HumanMessage("Hello Foo, I'm Bar"),
            new AIMessage("I'm an AI. I'm Foo. I'm Bar."),
        ]), self::asPairs($messages));
    }

    public function testFromMessagesIsComposableWithPartialVars(): void
    {
        $systemPrompt = new PromptTemplate(
            template: "Here's some context: {context}",
            inputVariables: ['context'],
        );
        $userPrompt = new PromptTemplate(
            template: "Hello {foo}, I'm {bar}",
            inputVariables: ['foo', 'bar'],
        );

        $chatPromptInner = ChatPromptTemplate::fromMessages([
            new SystemMessagePromptTemplate($systemPrompt),
            new HumanMessagePromptTemplate($userPrompt),
        ]);

        $chatPrompt = ChatPromptTemplate::fromMessages([
            $chatPromptInner->partial(['context' => 'This is a context', 'foo' => 'Foo']),
            AIMessagePromptTemplate::fromTemplate("I'm an AI. I'm {foo}. I'm {bar}."),
        ]);

        self::assertSame(['bar'], $chatPrompt->inputVariables);

        $messages = $chatPrompt->formatPromptValue(['bar' => 'Bar'])->toMessages();

        self::assertEquals(self::asPairs([
            new SystemMessage("Here's some context: This is a context"),
            new HumanMessage("Hello Foo, I'm Bar"),
            new AIMessage("I'm an AI. I'm Foo. I'm Bar."),
        ]), self::asPairs($messages));
    }

    public function testSimpleMessagePromptTemplate(): void
    {
        $prompt = new MessagesPlaceholder('foo');

        $messages = $prompt->formatMessages([
            'foo' => [new HumanMessage("Hello Foo, I'm Bar")],
        ]);

        self::assertEquals(
            self::asPairs([new HumanMessage("Hello Foo, I'm Bar")]),
            self::asPairs($messages)
        );
    }

    public function testMessagesPlaceholderOptional(): void
    {
        $prompt = new MessagesPlaceholder('foo', true);

        self::assertSame([], $prompt->formatMessages([]));
    }

    public function testMessagesPlaceholderOptionalInAChatPromptTemplate(): void
    {
        $prompt = ChatPromptTemplate::fromMessages([new MessagesPlaceholder('foo', true)]);

        self::assertSame([], $prompt->formatMessages([]));
    }

    public function testMessagesPlaceholderNotOptional(): void
    {
        $prompt = new MessagesPlaceholder('foo');

        $this->expectException(InputFormatError::class);
        $this->expectExceptionMessage(
            'Field "foo" in prompt uses a MessagesPlaceholder, which expects an array of BaseMessages as an input value. Received: undefined'
        );

        $prompt->formatMessages([]);
    }

    public function testMessagesPlaceholderNotOptionalWithInvalidInputShouldThrow(): void
    {
        $prompt = new MessagesPlaceholder('foo');
        $badInput = [['pageContent' => 'barbar', 'metadata' => []]];

        try {
            $prompt->formatMessages(['foo' => $badInput]);
            self::fail('expected an InputFormatError');
        } catch (InputFormatError $e) {
            self::assertSame('InputFormatError', $e->errorName);
            self::assertSame(
                "Field \"foo\" in prompt uses a MessagesPlaceholder, which expects an array of BaseMessages or coerceable values as input.\n\n"
                . 'Received value: ' . json_encode($badInput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
                . 'Additional message: Unable to coerce message from array: only human, AI, system, developer, or tool message coercion is currently supported.',
                $e->getMessage()
            );
        }
    }

    public function testMessagesPlaceholderShorthandInAChatPromptTemplateShouldThrowForInvalidSyntax(): void
    {
        $this->expectException(\RuntimeException::class);

        ChatPromptTemplate::fromMessages([['placeholder', 'foo']]);
    }

    public function testMessagesPlaceholderShorthandInAChatPromptTemplate(): void
    {
        $prompt = ChatPromptTemplate::fromMessages([['placeholder', '{foo}']]);

        $messages = $prompt->formatMessages([
            'foo' => [new HumanMessage('Hi there!'), new AIMessage('how r u')],
        ]);

        self::assertEquals(
            self::asPairs([new HumanMessage('Hi there!'), new AIMessage('how r u')]),
            self::asPairs($messages)
        );
    }

    public function testMessagesPlaceholderShorthandWithObjectFormat(): void
    {
        $prompt = ChatPromptTemplate::fromMessages([['placeholder', '{foo}']]);

        $messages = $prompt->formatMessages([
            'foo' => [
                ['type' => 'system', 'content' => 'some initial content'],
                [
                    'type' => 'human',
                    'content' => [
                        [
                            'text' => "page: 1\ndescription: One Purchase Flow\ntimestamp: '2024-06-04T14:46:46.062Z'\ntype: navigate\nscreenshot_present: true\n",
                            'type' => 'text',
                        ],
                        [
                            'text' => "page: 3\ndescription: intent_str=buy,mode_str=redirect,screenName_str=order-completed,\ntimestamp: '2024-06-04T14:46:58.846Z'\ntype: Screen View\nscreenshot_present: false\n",
                            'type' => 'text',
                        ],
                    ],
                ],
                ['type' => 'assistant', 'content' => 'some captivating response'],
            ],
        ]);

        self::assertSame('system', $messages[0]->getType());
        self::assertSame('some initial content', $messages[0]->text());
        self::assertSame('human', $messages[1]->getType());
        self::assertSame([
            [
                'text' => "page: 1\ndescription: One Purchase Flow\ntimestamp: '2024-06-04T14:46:46.062Z'\ntype: navigate\nscreenshot_present: true\n",
                'type' => 'text',
            ],
            [
                'text' => "page: 3\ndescription: intent_str=buy,mode_str=redirect,screenName_str=order-completed,\ntimestamp: '2024-06-04T14:46:58.846Z'\ntype: Screen View\nscreenshot_present: false\n",
                'type' => 'text',
            ],
        ], $messages[1]->content);
        self::assertSame('ai', $messages[2]->getType());
        self::assertSame('some captivating response', $messages[2]->text());
    }

    public function testMessagesPlaceholderWithInvalidShorthandShouldThrow(): void
    {
        $prompt = ChatPromptTemplate::fromMessages([['placeholder', '{foo}']]);

        $this->expectException(InputFormatError::class);

        $prompt->formatMessages(['foo' => [['badFormatting' => true]]]);
    }

    public function testUsingPartial(): void
    {
        $userPrompt = new PromptTemplate(template: '{foo}{bar}', inputVariables: ['foo', 'bar']);

        $prompt = new ChatPromptTemplate(
            promptMessages: [new HumanMessagePromptTemplate($userPrompt)],
            inputVariables: ['foo', 'bar'],
        );

        $partialPrompt = $prompt->partial(['foo' => 'foo']);

        // The original prompt is untouched.
        self::assertSame(['foo', 'bar'], $prompt->inputVariables);
        // The partial prompt has only the remaining variables.
        self::assertSame(['bar'], $partialPrompt->inputVariables);

        self::assertSame("Human: foobaz", $partialPrompt->format(['bar' => 'baz']));
    }

    public function testBaseMessage(): void
    {
        $prompt = ChatPromptTemplate::fromMessages([
            new SystemMessage('You are a chatbot {mock_variable}'),
            AIMessagePromptTemplate::fromTemplate('{name} is my name.'),
            new FunctionMessage(['content' => '{}', 'name' => 'get_weather']),
        ]);

        $value = $prompt->formatPromptValue(['name' => 'Bob']);

        self::assertSame(['name'], $prompt->inputVariables);
        self::assertSame([], $prompt->partialVariables);

        self::assertEquals(self::asPairs([
            new SystemMessage('You are a chatbot {mock_variable}'),
            new AIMessage('Bob is my name.'),
            new FunctionMessage(['content' => '{}', 'name' => 'get_weather']),
        ]), self::asPairs($value->toMessages()));
    }

    public function testDoesNotThrowIfNullIsPassedAsInputToMessagesPlaceholder(): void
    {
        $prompt = ChatPromptTemplate::fromMessages([
            ['system', 'some string'],
            new MessagesPlaceholder('chatHistory'),
            new MessagesPlaceholder('chatHistory2'),
            ['human', '{question}'],
        ]);

        $this->expectException(InputFormatError::class);
        $this->expectExceptionMessage('uses a MessagesPlaceholder');

        $prompt->formatMessages([
            'chatHistory' => null,
            'chatHistory2' => null,
            'question' => 'What is the meaning of life?',
        ]);
    }

    public function testMultiPartChatPromptTemplate(): void
    {
        $template = ChatPromptTemplate::fromMessages([
            ['system', 'You are an AI assistant named {name}'],
            [
                'human',
                [
                    ['type' => 'text', 'text' => 'What is in this object {objectName}'],
                ],
            ],
        ]);

        $messages = $template->formatMessages(['name' => 'Bob', 'objectName' => 'chair']);

        self::assertSame("You are an AI assistant named Bob", $messages[0]->text());
        self::assertSame([
            ['type' => 'text', 'text' => 'What is in this object chair'],
        ], $messages[1]->content);
    }

    public function testMultiPartChatPromptTemplateWithImage(): void
    {
        $myImage = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAA';
        $myUrl = 'https://www.example.com/image.png';

        $template = ChatPromptTemplate::fromMessages([
            ['system', 'You are an AI assistant named {name}'],
            [
                'human',
                [
                    ['type' => 'image_url', 'image_url' => "data:image/jpeg;base64,{$myImage}"],
                    ['type' => 'text', 'text' => 'What is in this object {objectName}'],
                    ['type' => 'image_url', 'image_url' => ['url' => '{myUrl}', 'detail' => 'high']],
                ],
            ],
        ]);

        $messages = $template->formatMessages([
            'name' => 'Bob',
            'objectName' => 'chair',
            'myImage' => $myImage,
            'myUrl' => $myUrl,
        ]);

        self::assertSame("You are an AI assistant named Bob", $messages[0]->text());
        self::assertSame([
            ['type' => 'image_url', 'image_url' => ['url' => "data:image/jpeg;base64,{$myImage}"]],
            ['type' => 'text', 'text' => 'What is in this object chair'],
            ['type' => 'image_url', 'image_url' => ['url' => $myUrl, 'detail' => 'high']],
        ], $messages[1]->content);
    }

    public function testMultiModalMultiPartChatPromptWorksWithInstancesOfBaseMessage(): void
    {
        $myImage = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAA';
        $myUrl = 'https://www.example.com/image.png';

        $inlineImageUrl = new HumanMessage([
            'content' => [
                ['type' => 'image_url', 'image_url' => "data:image/jpeg;base64,{$myImage}"],
            ],
        ]);
        $objectImageUrl = new HumanMessage([
            'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => "data:image/jpeg;base64,{$myImage}", 'detail' => 'high']],
            ],
        ]);
        $normalMessage = new HumanMessage([
            'content' => [
                ['type' => 'text', 'text' => 'What is in this object {objectName}'],
            ],
        ]);

        $template = ChatPromptTemplate::fromMessages([
            ['system', 'You are an AI assistant named {name}'],
            $inlineImageUrl,
            $normalMessage,
            $objectImageUrl,
            [
                'human',
                [
                    ['type' => 'text', 'text' => 'What is in this object {objectName}'],
                    ['type' => 'image_url', 'image_url' => ['url' => '{myUrl}', 'detail' => 'high']],
                ],
            ],
        ]);

        $messages = $template->formatMessages([
            'name' => 'Bob',
            'objectName' => 'chair',
            'myImage' => $myImage,
            'myUrl' => $myUrl,
        ]);

        // The upstream snapshot: a message passed in as a `BaseMessage` is used
        // verbatim except for its image URLs, so a text block inside one is left
        // untouched — only template-built blocks interpolate their text.
        self::assertCount(5, $messages);
        self::assertSame([
            ['type' => 'image_url', 'image_url' => "data:image/jpeg;base64,{$myImage}"],
        ], $messages[1]->content);
        self::assertSame([
            ['type' => 'text', 'text' => 'What is in this object {objectName}'],
        ], $messages[2]->content);
        self::assertSame([
            ['type' => 'image_url', 'image_url' => ['url' => "data:image/jpeg;base64,{$myImage}", 'detail' => 'high']],
        ], $messages[3]->content);
        self::assertSame([
            ['type' => 'text', 'text' => 'What is in this object chair'],
            ['type' => 'image_url', 'image_url' => ['url' => $myUrl, 'detail' => 'high']],
        ], $messages[4]->content);
    }

    public function testFormatComplexMessagesAndKeepAdditionalFields(): void
    {
        $examplePrompt = ChatPromptTemplate::fromMessages([
            [
                'human',
                [
                    ['type' => 'text', 'text' => '{input}', 'cache_control' => ['type' => 'ephemeral']],
                ],
            ],
            [
                'ai',
                [
                    ['type' => 'text', 'text' => '{output}', 'cache_control' => ['type' => 'ephemeral']],
                ],
            ],
        ]);

        $formatted = $examplePrompt->formatMessages(['input' => 'hello', 'output' => 'ciao']);

        self::assertCount(2, $formatted);

        self::assertSame('human', $formatted[0]->getType());
        self::assertSame(['type' => 'ephemeral'], $formatted[0]->content[0]['cache_control']);
        self::assertSame('hello', $formatted[0]->content[0]['text']);

        self::assertSame('ai', $formatted[1]->getType());
        self::assertSame(['type' => 'ephemeral'], $formatted[1]->content[0]['cache_control']);
        self::assertSame('ciao', $formatted[1]->content[0]['text']);
    }

    public function testFormatImageContentMessagesAndKeepAdditionalFields(): void
    {
        $examplePrompt = ChatPromptTemplate::fromMessages([
            [
                'human',
                [
                    ['type' => 'image_url', 'image_url' => '{image_url}', 'cache_control' => ['type' => 'ephemeral']],
                ],
            ],
        ]);

        $formatted = $examplePrompt->formatMessages(['image_url' => 'image_url']);

        self::assertCount(1, $formatted);
        self::assertSame('human', $formatted[0]->getType());
        self::assertSame(['type' => 'ephemeral'], $formatted[0]->content[0]['cache_control']);
    }

    public function testSerializedKwargsMatchTheTypeScriptShape(): void
    {
        $template = ChatPromptTemplate::fromMessages([
            ['system', 'You are an AI assistant named {name}'],
            [
                'human',
                [
                    ['type' => 'text', 'text' => '{text1}', 'cache_control' => ['type' => 'ephemeral']],
                    ['type' => 'audio', 'audio' => ['path' => '{myImage}']],
                ],
            ],
        ]);

        self::assertEquals([
            'lc' => 1,
            'type' => 'constructor',
            'id' => ['langchain_core', 'prompts', 'chat', 'ChatPromptTemplate'],
            'kwargs' => [
                'input_variables' => ['name', 'text1', 'myImage'],
                'messages' => [
                    [
                        'lc' => 1,
                        'type' => 'constructor',
                        'id' => ['langchain_core', 'prompts', 'chat', 'SystemMessagePromptTemplate'],
                        'kwargs' => [
                            'prompt' => [
                                'lc' => 1,
                                'type' => 'constructor',
                                'id' => ['langchain_core', 'prompts', 'prompt', 'PromptTemplate'],
                                'kwargs' => [
                                    'input_variables' => ['name'],
                                    'template' => 'You are an AI assistant named {name}',
                                    'template_format' => 'f-string',
                                ],
                            ],
                        ],
                    ],
                    [
                        'lc' => 1,
                        'type' => 'constructor',
                        'id' => ['langchain_core', 'prompts', 'chat', 'HumanMessagePromptTemplate'],
                        'kwargs' => [
                            'prompt' => [
                                [
                                    'lc' => 1,
                                    'type' => 'constructor',
                                    'id' => ['langchain_core', 'prompts', 'dict', 'DictPromptTemplate'],
                                    'kwargs' => [
                                        'input_variables' => ['text1'],
                                        'template' => [
                                            'cache_control' => ['type' => 'ephemeral'],
                                            'text' => '{text1}',
                                            'type' => 'text',
                                        ],
                                        'template_format' => 'f-string',
                                    ],
                                ],
                                [
                                    'lc' => 1,
                                    'type' => 'constructor',
                                    'id' => ['langchain_core', 'prompts', 'dict', 'DictPromptTemplate'],
                                    'kwargs' => [
                                        'input_variables' => ['myImage'],
                                        'template' => [
                                            'audio' => ['path' => '{myImage}'],
                                            'type' => 'audio',
                                        ],
                                        'template_format' => 'f-string',
                                    ],
                                ],
                            ],
                            'additional_options' => [],
                        ],
                    ],
                ],
            ],
            // `assertEquals`, not `assertSame`: the upstream assertion is
            // `toEqual`, which does not care about key order, and a vitest
            // snapshot sorts keys while this port preserves insertion order.
        ], json_decode((string) json_encode($template), true));
    }
}
