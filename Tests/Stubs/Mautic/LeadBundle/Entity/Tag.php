<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Entity;

final class Tag
{
    public function __construct(
        private string $tag,
    ) {
    }

    public function getTag(): string
    {
        return $this->tag;
    }
}
