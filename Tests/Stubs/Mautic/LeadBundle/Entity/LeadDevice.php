<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Entity;

/**
 * Test double for the Mautic LeadBundle LeadDevice entity.
 *
 * Only the accessors that {@see \MauticPlugin\MauticInsightsBundle\Service\PageHitPayloadBuilder}
 * reads are exposed; the real Mautic class has many more.
 */
class LeadDevice
{
    public ?string $stubDevice = null;

    public ?string $stubDeviceOsName = null;

    public ?string $stubDeviceOsShortName = null;

    public ?string $stubDeviceOsVersion = null;

    public ?string $stubDeviceOsPlatform = null;

    public ?string $stubDeviceBrand = null;

    public ?string $stubDeviceModel = null;

    /** @var array<string, mixed> */
    public array $stubClientInfo = [];

    public function getDevice(): ?string
    {
        return $this->stubDevice;
    }

    public function getDeviceOsName(): ?string
    {
        return $this->stubDeviceOsName;
    }

    public function getDeviceOsShortName(): ?string
    {
        return $this->stubDeviceOsShortName;
    }

    public function getDeviceOsVersion(): ?string
    {
        return $this->stubDeviceOsVersion;
    }

    public function getDeviceOsPlatform(): ?string
    {
        return $this->stubDeviceOsPlatform;
    }

    public function getDeviceBrand(): ?string
    {
        return $this->stubDeviceBrand;
    }

    public function getDeviceModel(): ?string
    {
        return $this->stubDeviceModel;
    }

    /**
     * @return array<string, mixed>
     */
    public function getClientInfo(): array
    {
        return $this->stubClientInfo;
    }
}
