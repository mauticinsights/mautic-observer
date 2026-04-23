<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Entity;

class Form
{
    public function __construct(
        private ?int $id = null,
        private string $name = '',
        private string $alias = '',
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

    public function getAlias(): string
    {
        return $this->alias;
    }
}
