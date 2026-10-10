<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Service;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\MauticInsightsBundle\Service\SafeEncryptionHelper;
use PHPUnit\Framework\TestCase;
use TypeError;

final class SafeEncryptionHelperTest extends TestCase
{
    public function testDecryptEmptyStringReturnsFalseWithoutCallingInner(): void
    {
        $inner = $this->createMock(EncryptionHelper::class);
        $inner->expects($this->never())->method('decrypt');

        $this->assertFalse((new SafeEncryptionHelper($inner))->decrypt(''));
    }

    public function testDecryptPlainTextReturnsValueUnchanged(): void
    {
        $inner = $this->createMock(EncryptionHelper::class);
        $inner->expects($this->never())->method('decrypt');

        $this->assertSame('plain-token', (new SafeEncryptionHelper($inner))->decrypt('plain-token'));
    }

    public function testDecryptDelegatesWhenValueContainsPipe(): void
    {
        $inner = $this->createMock(EncryptionHelper::class);
        $inner->expects($this->once())
            ->method('decrypt')
            ->with('cipher|iv', false)
            ->willReturn('secret');

        $this->assertSame('secret', (new SafeEncryptionHelper($inner))->decrypt('cipher|iv'));
    }

    public function testDecryptCatchesThrowableFromInner(): void
    {
        $inner = $this->createMock(EncryptionHelper::class);
        $inner->method('decrypt')->willThrowException(new TypeError('base64_decode(): null given'));

        $this->assertFalse((new SafeEncryptionHelper($inner))->decrypt('has|pipe'));
    }

    public function testEncryptSkipsAlreadyEncryptedValue(): void
    {
        $inner = $this->createMock(EncryptionHelper::class);
        $inner->expects($this->never())->method('encrypt');

        $this->assertSame('cipher|iv', (new SafeEncryptionHelper($inner))->encrypt('cipher|iv'));
    }

    public function testEncryptDelegatesPlainToken(): void
    {
        $inner = $this->createMock(EncryptionHelper::class);
        $inner->expects($this->once())
            ->method('encrypt')
            ->with('brand-new-token')
            ->willReturn('encrypted|blob');

        $this->assertSame('encrypted|blob', (new SafeEncryptionHelper($inner))->encrypt('brand-new-token'));
    }
}
