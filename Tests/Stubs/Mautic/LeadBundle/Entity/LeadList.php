<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Entity;

final class LeadList
{
    /** Mirrors {@see \Mautic\CoreBundle\Entity\FormEntity::$deletedId} after {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity()}. */
    public ?int $deletedId = null;

    public function __construct(
        private ?int $listId,
        private string $name = '',
        private string $alias = '',
        private ?string $publicName = null,
        private bool $isPublished = true,
    ) {
    }

    public function getId(): ?int
    {
        return $this->listId;
    }

    public function getDeletedId(): ?int
    {
        return $this->deletedId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function getPublicName(): ?string
    {
        return $this->publicName;
    }

    public function getIsPublished(): bool
    {
        return $this->isPublished;
    }
}
