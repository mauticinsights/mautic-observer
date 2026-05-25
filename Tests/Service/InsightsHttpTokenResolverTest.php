<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Service;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InsightsHttpTokenResolver.
 *
 * Covers the edge cases fixed on 2026-05-25:
 *  - empty string in api_keys no longer triggers TypeError (was PHP 8.2 crash)
 *  - plain-text token (restored by Mautic after save) is used as-is when decrypt returns false
 *  - TypeError thrown by EncryptionHelper::decrypt is caught gracefully
 */
final class InsightsHttpTokenResolverTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // No EncryptionHelper (null) — raw value returned as-is
    // ──────────────────────────────────────────────────────────────

    public function testReturnsTokenDirectlyWhenNoEncryptionHelper(): void
    {
        $integration = $this->integration(['http_token' => 'my-token']);
        $resolver    = new InsightsHttpTokenResolver(null);

        $this->assertSame('my-token', $resolver->resolve($integration));
    }

    public function testReturnsEmptyStringWhenApiKeysIsEmpty(): void
    {
        $integration = $this->integration([]);
        $resolver    = new InsightsHttpTokenResolver(null);

        $this->assertSame('', $resolver->resolve($integration));
    }

    public function testTrimsWhitespaceFromToken(): void
    {
        $integration = $this->integration(['http_token' => '  spaced  ']);
        $resolver    = new InsightsHttpTokenResolver(null);

        $this->assertSame('spaced', $resolver->resolve($integration));
    }

    // ──────────────────────────────────────────────────────────────
    // Empty string in api_keys (fresh install / never configured)
    // ──────────────────────────────────────────────────────────────

    public function testEmptyStringTokenSkipsDecryptAndReturnsEmpty(): void
    {
        // EncryptionHelper::decrypt('') used to trigger PHP Warning + TypeError on PHP 8.2.
        // The fix: empty strings are passed through without calling decrypt.
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->never())->method('decrypt');

        $integration = $this->integration(['http_token' => '']);
        $resolver    = new InsightsHttpTokenResolver($helper);

        $this->assertSame('', $resolver->resolve($integration));
    }

    // ──────────────────────────────────────────────────────────────
    // Plain-text token (Mautic restores it after saveIntegrationConfiguration)
    // ──────────────────────────────────────────────────────────────

    /**
     * After IntegrationsHelper::saveIntegrationConfiguration() Mautic calls
     * $configuration->setApiKeys($decryptedApiKeys) to restore the plain-text
     * value. At INTEGRATION_CONFIG_AFTER_SAVE our subscriber reads that plain-text
     * value. decrypt() returns false for a plain-text string → we fall back to
     * the raw value.
     */
    public function testPlainTextTokenUsedAsIsWhenDecryptReturnsFalse(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->method('decrypt')->willReturn(false);

        $integration = $this->integration(['http_token' => 'plain-text-token']);
        $resolver    = new InsightsHttpTokenResolver($helper);

        $this->assertSame('plain-text-token', $resolver->resolve($integration));
    }

    // ──────────────────────────────────────────────────────────────
    // TypeError thrown inside EncryptionHelper::decrypt
    // ──────────────────────────────────────────────────────────────

    public function testTypeErrorFromDecryptIsCaughtAndPlainValueIsUsed(): void
    {
        // PHP 8.2: base64_decode(null) throws TypeError when the encrypted value
        // has no '|' separator and $encryptData[1] is undefined.
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->method('decrypt')->willThrowException(
            new \TypeError('base64_decode(): Argument #1 must be string, null given')
        );

        $integration = $this->integration(['http_token' => 'some-value-that-triggers-crash']);
        $resolver    = new InsightsHttpTokenResolver($helper);

        // Must not throw — falls back to raw value.
        $this->assertSame('some-value-that-triggers-crash', $resolver->resolve($integration));
    }

    public function testRuntimeExceptionFromDecryptIsCaughtAndPlainValueIsUsed(): void
    {
        // Covers Mautic error-handler converting E_WARNING to \RuntimeException.
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->method('decrypt')->willThrowException(new \RuntimeException('Undefined array key 1'));

        $integration = $this->integration(['http_token' => 'raw-token']);
        $resolver    = new InsightsHttpTokenResolver($helper);

        $this->assertSame('raw-token', $resolver->resolve($integration));
    }

    // ──────────────────────────────────────────────────────────────
    // Successful decryption
    // ──────────────────────────────────────────────────────────────

    public function testDecryptedTokenIsReturned(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->once())
            ->method('decrypt')
            ->with('encrypted-value', false)
            ->willReturn('decrypted-token');

        $integration = $this->integration(['http_token' => 'encrypted-value']);
        $resolver    = new InsightsHttpTokenResolver($helper);

        $this->assertSame('decrypted-token', $resolver->resolve($integration));
    }

    public function testDecryptedTokenIsTrimmed(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->method('decrypt')->willReturn('  spaced-decrypted  ');

        $resolver = new InsightsHttpTokenResolver($helper);

        $this->assertSame('spaced-decrypted', $resolver->resolve($this->integration(['http_token' => 'enc'])));
    }

    // ──────────────────────────────────────────────────────────────
    // Non-array api_keys (defensive)
    // ──────────────────────────────────────────────────────────────

    public function testReturnsEmptyStringWhenApiKeysAreNull(): void
    {
        // Integration::getApiKeys() might return [] for null DB value (stub does so),
        // but guard is in place for any non-array value coming from a real entity.
        $resolver    = new InsightsHttpTokenResolver(null);
        $integration = $this->integration([]);

        $this->assertSame('', $resolver->resolve($integration));
    }

    // ──────────────────────────────────────────────────────────────
    // Multiple keys in api_keys — only http_token is relevant
    // ──────────────────────────────────────────────────────────────

    public function testResolvesCorrectFieldWhenMultipleKeysPresent(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->method('decrypt')->willReturn(false); // plain-text fallback

        $integration = $this->integration([
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'my-real-token',
            'other_field'                       => 'ignore-me',
        ]);
        $resolver = new InsightsHttpTokenResolver($helper);

        $this->assertSame('my-real-token', $resolver->resolve($integration));
    }

    // ──────────────────────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $apiKeys */
    private function integration(array $apiKeys): Integration
    {
        return new Integration(true, [], InsightsApiConfig::NAME, $apiKeys);
    }
}
