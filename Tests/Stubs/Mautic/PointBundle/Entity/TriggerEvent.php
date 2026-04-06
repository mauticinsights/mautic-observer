<?php

declare(strict_types=1);

namespace Mautic\PointBundle\Entity;

/**
 * Minimal test double for point trigger step ({@see TriggerEvent}).
 */
final class TriggerEvent
{
    public function __construct(
        private ?int $id = null,
        private string $name = '',
        private string $type = 'email.send',
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
