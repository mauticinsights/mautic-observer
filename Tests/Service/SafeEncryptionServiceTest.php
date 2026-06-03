<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Service;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\MauticInsightsBundle\Service\SafeEncryptionService;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Unit tests for SafeEncryptionService.
 *
 * SafeEncryptionService wraps EncryptionHelper to prevent PHP 8.2 TypeError
 * when Mautic's api_keys contain empty strings (fresh install) or plain-text
 * values (restored after IntegrationsHelper::saveIntegrationConfiguration()).
 */
final class SafeEncryptionServiceTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // decrypt() — null / empty / non-encrypted input
    // ──────────────────────────────────────────────────────────────

    public function testDecryptNullReturnsEmptyArray(): void
    {
        $service = $this->makeService();

        $this->assertSame([], $service->decrypt(null));
    }

    public function testDecryptEmptyArrayReturnsEmptyArray(): void
    {
        $service = $this->makeService();

        $this->assertSame([], $service->decrypt([]));
    }

    public function testDecryptArrayWithEmptyStringPassesThroughWithoutCallingHelper(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->never())->method('decrypt');

        $service = new SafeEncryptionService($helper);
        $result = $service->decrypt(['http_token' => '']);

        $this->assertSame(['http_token' => ''], $result);
    }

    public function testDecryptArrayWithNullValuePassesThroughWithoutCallingHelper(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->never())->method('decrypt');

        $service = new SafeEncryptionService($helper);
        $result = $service->decrypt(['http_token' => null]);

        $this->assertSame(['http_token' => null], $result);
    }

    public function testDecryptPlainTextWithoutPipeSeparatorReturnsValueUnchanged(): void
    {
        // Plain-text token after IntegrationsHelper restores it — no '|' separator.
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->never())->method('decrypt');

        $service = new SafeEncryptionService($helper);
        $result = $service->decrypt(['http_token' => 'plain-token-no-pipe']);

        $this->assertSame(['http_token' => 'plain-token-no-pipe'], $result);
    }

    public function testDecryptScalarPlainTextReturnsValueUnchanged(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->never())->method('decrypt');

        $service = new SafeEncryptionService($helper);

        $this->assertSame('plain-text-no-pipe', $service->decrypt('plain-text-no-pipe'));
    }

    public function testDecryptScalarEmptyStringReturnsFalse(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->never())->method('decrypt');

        $service = new SafeEncryptionService($helper);

        $this->assertFalse($service->decrypt(''));
    }

    // ──────────────────────────────────────────────────────────────
    // decrypt() — valid encrypted-looking value (contains '|')
    // ──────────────────────────────────────────────────────────────

    public function testDecryptArrayDelegatesWhenValueContainsPipe(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->once())
            ->method('decrypt')
            ->with('base64cipher|base64iv', false)
            ->willReturn('decrypted-token');

        $service = new SafeEncryptionService($helper);
        $result = $service->decrypt(['http_token' => 'base64cipher|base64iv']);

        $this->assertSame(['http_token' => 'decrypted-token'], $result);
    }

    public function testDecryptScalarDelegatesWhenValueContainsPipe(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->once())
            ->method('decrypt')
            ->with('cipher|iv', false)
            ->willReturn('secret');

        $service = new SafeEncryptionService($helper);

        $this->assertSame('secret', $service->decrypt('cipher|iv'));
    }

    public function testDecryptPassesOnlyPrimaryCipherFlag(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->once())
            ->method('decrypt')
            ->with('cipher|iv', true)
            ->willReturn('secret');

        $service = new SafeEncryptionService($helper);
        $service->decrypt('cipher|iv', true);
    }

    // ──────────────────────────────────────────────────────────────
    // decrypt() — EncryptionHelper throws (should not propagate)
    // ──────────────────────────────────────────────────────────────

    public function testDecryptCatchesThrowableFromHelperAndReturnsFalseForScalar(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->method('decrypt')->willThrowException(new TypeError('base64_decode(): null given'));

        $service = new SafeEncryptionService($helper);

        // Must not throw — returns false gracefully
        $result = $service->decrypt('has|pipe');
        $this->assertFalse($result);
    }

    public function testDecryptCatchesThrowableFromHelperAndReturnsFalseForArrayValue(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->method('decrypt')->willThrowException(new TypeError('base64_decode(): null given'));

        $service = new SafeEncryptionService($helper);
        $result = $service->decrypt(['http_token' => 'has|pipe']);

        $this->assertSame(['http_token' => false], $result);
    }

    // ──────────────────────────────────────────────────────────────
    // decrypt() — mixed array (some empty, some encrypted)
    // ──────────────────────────────────────────────────────────────

    public function testDecryptMixedArrayHandlesEachKeyIndependently(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->once())
            ->method('decrypt')
            ->with('cipher|iv', false)
            ->willReturn('decrypted');

        $service = new SafeEncryptionService($helper);
        $result = $service->decrypt([
            'http_token' => 'cipher|iv',
            'empty_field' => '',
            'null_field' => null,
            'plain_field' => 'nopipe',
        ]);

        $this->assertSame([
            'http_token' => 'decrypted',
            'empty_field' => '',
            'null_field' => null,
            'plain_field' => 'nopipe',
        ], $result);
    }

    // ──────────────────────────────────────────────────────────────
    // encrypt()
    // ──────────────────────────────────────────────────────────────

    public function testEncryptSkipsAlreadyEncryptedValue(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->never())->method('encrypt');

        $service = new SafeEncryptionService($helper);
        $result = $service->encrypt(['http_token' => 'cipher|iv']);

        $this->assertSame(['http_token' => 'cipher|iv'], $result);
    }

    public function testEncryptNewPlainTokenDelegatesToHelper(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->once())
            ->method('encrypt')
            ->with('brand-new-token')
            ->willReturn('encrypted|blob');

        $service = new SafeEncryptionService($helper);

        $this->assertSame(
            ['http_token' => 'encrypted|blob'],
            $service->encrypt(['http_token' => 'brand-new-token']),
        );
    }

    public function testEncryptScalarDelegatesToHelper(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->once())
            ->method('encrypt')
            ->with('my-token')
            ->willReturn('encrypted-result');

        $service = new SafeEncryptionService($helper);

        $this->assertSame('encrypted-result', $service->encrypt('my-token'));
    }

    public function testEncryptArrayEncryptsEachValue(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects($this->exactly(2))
            ->method('encrypt')
            ->willReturnMap([
                ['token-a', 'enc-a'],
                ['token-b', 'enc-b'],
            ]);

        $service = new SafeEncryptionService($helper);
        $result = $service->encrypt(['field_a' => 'token-a', 'field_b' => 'token-b']);

        $this->assertSame(['field_a' => 'enc-a', 'field_b' => 'enc-b'], $result);
    }

    // ──────────────────────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────────────────────

    private function makeService(): SafeEncryptionService
    {
        return new SafeEncryptionService($this->createMock(EncryptionHelper::class));
    }
}
