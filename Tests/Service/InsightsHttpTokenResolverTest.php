<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Service;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use PHPUnit\Framework\TestCase;

final class InsightsHttpTokenResolverTest extends TestCase
{
    public function testResolvesPlainTextTokenWithoutDecryption(): void
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'my-plain-token',
        ]);

        $resolver = new InsightsHttpTokenResolver(null);
        self::assertSame('my-plain-token', $resolver->resolve($integration));
    }

    public function testReturnsEmptyStringWhenNoToken(): void
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, []);
        $resolver = new InsightsHttpTokenResolver(null);
        self::assertSame('', $resolver->resolve($integration));
    }

    public function testPlainTextTokenDoesNotTriggerDecryption(): void
    {
        // EncryptionHelper::decrypt() throws a PHP Warning when the value has no '|'.
        // The resolver must skip decryption for plain-text values and never call decrypt()
        // on them, even when an EncryptionHelper is injected.
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->expects(self::never())->method('decrypt');

        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'plain-no-pipe',
        ]);

        $resolver = new InsightsHttpTokenResolver($helper);
        self::assertSame('plain-no-pipe', $resolver->resolve($integration));
    }

    public function testEncryptedTokenIsDecrypted(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->method('decrypt')->willReturn('decrypted-token');

        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'base64abc|base64def',
        ]);

        $resolver = new InsightsHttpTokenResolver($helper);
        self::assertSame('decrypted-token', $resolver->resolve($integration));
    }

    public function testFallsBackToRawValueWhenDecryptionFails(): void
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->method('decrypt')->willReturn(false);

        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'base64abc|base64def',
        ]);

        $resolver = new InsightsHttpTokenResolver($helper);
        self::assertSame('base64abc|base64def', $resolver->resolve($integration));
    }
}
