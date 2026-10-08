<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Azure;

use LangChain\LanguageModels\Chat\OpenAI\Utils\Azure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `utils/azure.ts` has no upstream unit tests; these pin each branch of
 * `getEndpoint` and the header helpers against the documented behaviour.
 */
#[CoversClass(Azure::class)]
final class AzureUtilsTest extends TestCase
{
    public function testABasePathWinsOverAnEndpoint(): void
    {
        self::assertSame('https://base/x/dep', Azure::getEndpoint([
            'azureOpenAIApiKey' => 'k', 'azureOpenAIBasePath' => 'https://base/x', 'azureOpenAIEndpoint' => 'https://e', 'azureOpenAIApiDeploymentName' => 'dep',
        ]));
    }

    public function testAnEndpointGetsTheDeploymentsPath(): void
    {
        self::assertSame('https://e.openai.azure.com/openai/deployments/dep', Azure::getEndpoint([
            'azureADTokenProvider' => static fn (): string => 't', 'azureOpenAIEndpoint' => 'https://e.openai.azure.com', 'azureOpenAIApiDeploymentName' => 'dep',
        ]));
    }

    public function testAnInstanceNameBuildsTheHost(): void
    {
        self::assertSame('https://i.openai.azure.com/openai/deployments/dep', Azure::getEndpoint([
            'azureOpenAIApiKey' => 'k', 'azureOpenAIApiInstanceName' => 'i', 'azureOpenAIApiDeploymentName' => 'dep',
        ]));
    }

    public function testAKeyWithoutAnInstanceNameIsRefused(): void
    {
        $this->expectExceptionMessage('azureOpenAIApiInstanceName is required when using azureOpenAIApiKey');

        Azure::getEndpoint(['azureOpenAIApiKey' => 'k', 'azureOpenAIApiDeploymentName' => 'dep']);
    }

    public function testAKeyWithoutADeploymentIsRefused(): void
    {
        $this->expectExceptionMessage('azureOpenAIApiDeploymentName is a required parameter when using azureOpenAIApiKey');

        Azure::getEndpoint(['azureOpenAIApiKey' => 'k', 'azureOpenAIApiInstanceName' => 'i']);
    }

    public function testWithoutCredentialsTheCustomBaseUrlIsReturnedAsIs(): void
    {
        self::assertSame('https://proxy/v1', Azure::getEndpoint(['baseURL' => 'https://proxy/v1', 'azureOpenAIApiDeploymentName' => 'dep']));
        self::assertNull(Azure::getEndpoint([]));
    }

    public function testHeadersAreLowercasedAndNonStringValuesDropped(): void
    {
        self::assertSame(
            ['content-type' => 'application/json', 'x-a' => '1'],
            Azure::normalizeHeaders(['Content-Type' => 'application/json', 'X-A' => '1', 'X-N' => null, 'X-L' => ['a']]),
        );
        self::assertSame(['x-pair' => 'v'], Azure::normalizeHeaders([['X-Pair', 'v']]));
        self::assertSame([], Azure::normalizeHeaders(null));
    }

    public function testTheUserAgentNamesTheLibraryAndRuntime(): void
    {
        $plain = Azure::getHeadersWithUserAgent(null);
        $azure = Azure::getHeadersWithUserAgent(['X-A' => '1'], true, '2.0.0');

        self::assertMatchesRegularExpression('#^langchainjs-openai/1\.0\.0 \(php/[\d.]+; \w+; \w+\)$#', $plain['User-Agent']);
        self::assertStringStartsWith('langchainjs-azure-openai/2.0.0 (php/', $azure['User-Agent']);
        self::assertSame('1', $azure['x-a']);
    }

    public function testACallerUserAgentIsAppendedNotDuplicated(): void
    {
        $headers = Azure::getHeadersWithUserAgent(['user-agent' => ' my-app/1']);

        self::assertCount(1, $headers);
        self::assertStringEndsWith(') my-app/1', $headers['User-Agent']);
    }
}
