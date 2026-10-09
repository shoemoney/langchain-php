<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangGraph\Agents\Middleware\PiiDetectionError;
use LangGraph\Agents\Middleware\PiiDetectors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The detector `describe` blocks of `langchain/src/agents/middleware/tests/pii.test.ts` (Email, Credit Card, IP,
 * MAC Address and URL Detection), plus the strategy and rule-resolution behaviour the agent-level cases rely on.
 */
#[CoversClass(PiiDetectors::class)]
#[CoversClass(PiiDetectionError::class)]
final class PiiDetectorsTest extends TestCase
{
    // --- Email Detection

    public function testShouldDetectValidEmail(): void
    {
        $matches = PiiDetectors::detectEmail('Contact me at john.doe@example.com for more info.');

        self::assertCount(1, $matches);
        self::assertSame('john.doe@example.com', $matches[0]['text']);
        self::assertSame(14, $matches[0]['start']);
        self::assertSame(34, $matches[0]['end']);
    }

    public function testShouldDetectMultipleEmails(): void
    {
        $matches = PiiDetectors::detectEmail('Email alice@test.com or bob@company.org');

        self::assertCount(2, $matches);
        self::assertSame('alice@test.com', $matches[0]['text']);
        self::assertSame('bob@company.org', $matches[1]['text']);
    }

    public function testShouldNotDetectInvalidEmailFormats(): void
    {
        self::assertCount(0, PiiDetectors::detectEmail('Invalid emails: @test.com, user@, user@domain'));
    }

    public function testShouldNotDetectWhenNoEmailPresent(): void
    {
        self::assertCount(0, PiiDetectors::detectEmail('This text has no email addresses.'));
    }

    // --- Credit Card Detection

    public function testShouldDetectValidCreditCard(): void
    {
        // Valid Visa test number.
        $matches = PiiDetectors::detectCreditCard('Card: 4532015112830366');

        self::assertCount(1, $matches);
        self::assertStringContainsString('4532015112830366', $matches[0]['text']);
    }

    public function testShouldDetectCreditCardWithSpaces(): void
    {
        // Valid Mastercard test number.
        $matches = PiiDetectors::detectCreditCard('Card: 5425 2334 3010 9903');

        self::assertCount(1, $matches);
        self::assertStringContainsString('5425', $matches[0]['text']);
    }

    public function testShouldDetectCreditCardWithDashes(): void
    {
        self::assertCount(1, PiiDetectors::detectCreditCard('Card: 4532-0151-1283-0366'));
    }

    public function testShouldNotDetectInvalidLuhnChecksum(): void
    {
        self::assertCount(0, PiiDetectors::detectCreditCard('Card: 1234567890123456'));
    }

    public function testShouldNotDetectWhenNoCreditCardPresent(): void
    {
        self::assertCount(0, PiiDetectors::detectCreditCard('No cards here.'));
    }

    public function testDetectsMultipleCreditCards(): void
    {
        $matches = PiiDetectors::detectCreditCard('Card: 4532015112830366, Card: 5425233430109903');

        self::assertCount(2, $matches);
        self::assertStringContainsString('4532015112830366', $matches[0]['text']);
        self::assertStringContainsString('5425233430109903', $matches[1]['text']);
    }

    // --- IP Detection

    public function testShouldDetectValidIpv4(): void
    {
        $matches = PiiDetectors::detectIP('Server IP: 192.168.1.1');

        self::assertCount(1, $matches);
        self::assertSame('192.168.1.1', $matches[0]['text']);
    }

    public function testShouldDetectMultipleIps(): void
    {
        $matches = PiiDetectors::detectIP('Connect to 10.0.0.1 or 8.8.8.8');

        self::assertCount(2, $matches);
        self::assertSame('10.0.0.1', $matches[0]['text']);
        self::assertSame('8.8.8.8', $matches[1]['text']);
    }

    public function testShouldNotDetectInvalidIpOutOfRangeOctets(): void
    {
        self::assertCount(0, PiiDetectors::detectIP('Not an IP: 999.999.999.999'));
    }

    public function testShouldNotDetectWhenNoIpPresent(): void
    {
        self::assertCount(0, PiiDetectors::detectIP('No IP addresses here.'));
    }

    // --- MAC Address Detection

    public function testShouldDetectMacWithColons(): void
    {
        $matches = PiiDetectors::detectMacAddress('MAC: 00:1A:2B:3C:4D:5E');

        self::assertCount(1, $matches);
        self::assertSame('00:1A:2B:3C:4D:5E', $matches[0]['text']);
    }

    public function testShouldDetectMacWithDashes(): void
    {
        $matches = PiiDetectors::detectMacAddress('MAC: 00-1A-2B-3C-4D-5E');

        self::assertCount(1, $matches);
        self::assertSame('00-1A-2B-3C-4D-5E', $matches[0]['text']);
    }

    public function testShouldDetectLowercaseMac(): void
    {
        $matches = PiiDetectors::detectMacAddress('MAC: aa:bb:cc:dd:ee:ff');

        self::assertCount(1, $matches);
        self::assertSame('aa:bb:cc:dd:ee:ff', $matches[0]['text']);
    }

    public function testShouldNotDetectWhenNoMacPresent(): void
    {
        self::assertCount(0, PiiDetectors::detectMacAddress('No MAC address here.'));
    }

    public function testShouldNotDetectPartialMac(): void
    {
        self::assertCount(0, PiiDetectors::detectMacAddress('Partial: 00:1A:2B:3C'));
    }

    public function testDetectsMultipleMacAddresses(): void
    {
        $matches = PiiDetectors::detectMacAddress('MAC: 00:1A:2B:3C:4D:5E, MAC: 00-1A-2B-3C-4D-5F');

        self::assertCount(2, $matches);
        self::assertSame('00:1A:2B:3C:4D:5E', $matches[0]['text']);
        self::assertSame('00-1A-2B-3C-4D-5F', $matches[1]['text']);
    }

    // --- URL Detection

    public function testShouldDetectHttpUrl(): void
    {
        $matches = PiiDetectors::detectUrl('Visit http://example.com for details.');

        self::assertCount(1, $matches);
        self::assertSame('http://example.com', $matches[0]['text']);
    }

    public function testShouldDetectHttpsUrl(): void
    {
        $matches = PiiDetectors::detectUrl('Visit https://secure.example.com/path');

        self::assertCount(1, $matches);
        self::assertSame('https://secure.example.com/path', $matches[0]['text']);
    }

    public function testShouldDetectWwwUrl(): void
    {
        $matches = PiiDetectors::detectUrl('Check www.example.com');

        self::assertCount(1, $matches);
        self::assertSame('www.example.com', $matches[0]['text']);
    }

    public function testShouldDetectMultipleUrls(): void
    {
        $matches = PiiDetectors::detectUrl('Visit http://test.com and https://example.org');

        self::assertCount(2, $matches);
        self::assertSame('http://test.com', $matches[0]['text']);
        self::assertSame('https://example.org', $matches[1]['text']);
    }

    public function testShouldNotDetectWhenNoUrlPresent(): void
    {
        self::assertCount(0, PiiDetectors::detectUrl('No URLs here.'));
    }

    public function testUrlDetectionIsCaseInsensitiveAndStopsAtDelimiters(): void
    {
        $matches = PiiDetectors::detectUrl('See HTTPS://Example.com/a?b=1, or "www.x.org"<br>');

        self::assertSame(['HTTPS://Example.com/a?b=1,', 'www.x.org'], array_column($matches, 'text'));
    }

    // --- Strategies

    public function testApplyStrategyReturnsContentWhenThereAreNoMatches(): void
    {
        self::assertSame('nothing', PiiDetectors::applyStrategy('nothing', [], 'block', 'email'));
    }

    public function testRedactReplacesEveryMatchWithAnUppercasedMarker(): void
    {
        $content = 'Contact alice@test.com or bob@test.com';

        $result = PiiDetectors::applyStrategy($content, PiiDetectors::detectEmail($content), 'redact', 'email');

        self::assertSame('Contact [REDACTED_EMAIL] or [REDACTED_EMAIL]', $result);
    }

    public function testMaskEmailKeepsTheFirstCharacterAndTheDomain(): void
    {
        $content = 'Email: user@example.com';

        self::assertSame('Email: u***@example.com', PiiDetectors::applyStrategy($content, PiiDetectors::detectEmail($content), 'mask', 'email'));
    }

    public function testMaskCreditCardKeepsTheLastFourDigits(): void
    {
        $content = 'Card: 4532-0151-1283-0366';

        self::assertSame('Card: ****-****-****-0366', PiiDetectors::applyStrategy($content, PiiDetectors::detectCreditCard($content), 'mask', 'credit_card'));
    }

    public function testMaskDefaultShowsTheLastFourCharacters(): void
    {
        $content = 'IP: 192.168.1.100';

        self::assertSame('IP: *********.100', PiiDetectors::applyStrategy($content, PiiDetectors::detectIP($content), 'mask', 'ip'));
    }

    public function testMaskShortTextIsNotMaskedBeyondItsLength(): void
    {
        $matches = [['text' => 'abc', 'start' => 0, 'end' => 3]];

        self::assertSame('abc', PiiDetectors::applyStrategy('abc', $matches, 'mask', 'custom'));
    }

    public function testHashIsASha256PrefixAndDeterministic(): void
    {
        $content = 'Email: test@example.com';
        $matches = PiiDetectors::detectEmail($content);

        $result = PiiDetectors::applyStrategy($content, $matches, 'hash', 'email');

        self::assertSame('Email: <email_hash:' . substr(hash('sha256', 'test@example.com'), 0, 8) . '>', $result);
        self::assertSame($result, PiiDetectors::applyStrategy($content, $matches, 'hash', 'email'));
    }

    public function testBlockThrowsWithTheTypeAndTheMatches(): void
    {
        $content = 'Emails: alice@test.com and bob@test.com';

        try {
            PiiDetectors::applyStrategy($content, PiiDetectors::detectEmail($content), 'block', 'email');
            self::fail('Should have thrown PiiDetectionError');
        } catch (PiiDetectionError $error) {
            self::assertSame('email', $error->piiType);
            self::assertCount(2, $error->matches);
            self::assertSame('PII detected: email found 2 occurrence(s)', $error->getMessage());
            self::assertSame('PIIDetectionError', $error->errorName());
        }
    }

    public function testAnUnknownStrategyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown strategy: shred');

        PiiDetectors::applyStrategy('a@b.co', [['text' => 'a@b.co', 'start' => 0, 'end' => 6]], 'shred', 'email');
    }

    public function testMultibyteContentKeepsItsOffsetsConsistent(): void
    {
        $content = 'Zoë wrote: zoe@example.com ✓';

        $result = PiiDetectors::applyStrategy($content, PiiDetectors::detectEmail($content), 'redact', 'email');

        self::assertSame('Zoë wrote: [REDACTED_EMAIL] ✓', $result);
    }

    // --- resolveRedactionRule

    public function testResolveUsesTheBuiltInDetector(): void
    {
        $rule = PiiDetectors::resolveRedactionRule(['piiType' => 'ip', 'strategy' => 'mask']);

        self::assertSame('ip', $rule['piiType']);
        self::assertSame('mask', $rule['strategy']);
        self::assertCount(1, ($rule['detector'])('at 10.0.0.1'));
    }

    public function testResolveRejectsAnUnknownTypeWithoutADetector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown PII type: unknown_type. Must be one of: email, credit_card, ip, mac_address, url, or provide a custom detector.');

        PiiDetectors::resolveRedactionRule(['piiType' => 'unknown_type', 'strategy' => 'redact']);
    }

    public function testResolveCompilesABarePatternString(): void
    {
        $rule = PiiDetectors::resolveRedactionRule(['piiType' => 'api_key', 'strategy' => 'redact', 'detector' => 'sk-[a-zA-Z0-9]{32}']);

        $matches = ($rule['detector'])('Key: sk-abcdefghijklmnopqrstuvwxyz123456 and sk-ABCDEFGHIJKLMNOPQRSTUVWXYZ654321');

        self::assertCount(2, $matches);
        self::assertSame(5, $matches[0]['start']);
    }

    public function testResolveKeepsADelimitedPatternWithItsFlags(): void
    {
        $rule = PiiDetectors::resolveRedactionRule(['piiType' => 'custom_type', 'strategy' => 'redact', 'detector' => '/secret-\d+/i']);

        self::assertSame(['SECRET-12', 'secret-3'], array_column(($rule['detector'])('SECRET-12 and secret-3'), 'text'));
    }

    public function testResolveKeepsACallableDetector(): void
    {
        $detector = static fn (string $content): array => [];

        $rule = PiiDetectors::resolveRedactionRule(['piiType' => 'custom', 'strategy' => 'redact', 'detector' => $detector]);

        self::assertSame($detector, $rule['detector']);
    }

    public function testAnInvalidPatternFailsWhenItRuns(): void
    {
        $rule = PiiDetectors::resolveRedactionRule(['piiType' => 'bad', 'strategy' => 'redact', 'detector' => '(unclosed']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PII detector pattern');

        @($rule['detector'])('anything');
    }
}
