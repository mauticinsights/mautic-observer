<?php

declare(strict_types=1);

namespace Mautic\PageBundle\Entity;

class Page
{
    public function __construct(
        private ?int $id = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
