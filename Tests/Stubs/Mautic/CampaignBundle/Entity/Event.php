<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Entity;

/**
 * Test double — campaign node/step (not a Symfony Event).
 */
final class Event
{
    public function __construct(
        public string $type = '',
        public string $name = '',
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
