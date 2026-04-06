<?php

declare(strict_types=1);

namespace Mautic\UserBundle\Entity;

/**
 * Test double for {@see User}.
 */
final class User
{
    public function __construct(
        private ?int $id,
        private bool $admin,
        private string $timezone = '',
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }
}
