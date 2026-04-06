<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Entity;

class Form
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
