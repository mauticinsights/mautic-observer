<?php

declare(strict_types=1);

namespace Mautic\PageBundle\Entity;

/**
 * Test double for the Mautic PageBundle Redirect entity (trackable short-links).
 */
class Redirect
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
