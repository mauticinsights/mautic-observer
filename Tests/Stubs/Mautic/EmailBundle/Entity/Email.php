<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Entity;

use Mautic\LeadBundle\Entity\LeadList;

final class Email
{
    /** Mirrors {@see \Mautic\CoreBundle\Entity\FormEntity::$deletedId} after {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity()}. */
    public ?int $deletedId = null;

    /** @var list<LeadList> segment associations ({@see Email::getLists()}). */
    public array $lists = [];

    public function __construct(
        private ?int $id,
        private string $name = '',
        private string $subject = '',
        private string $emailType = 'template',
        private bool $isPublished = true,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeletedId(): ?int
    {
        return $this->deletedId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getEmailType(): string
    {
        return $this->emailType;
    }

    public function getIsPublished(): bool
    {
        return $this->isPublished;
    }

    /**
     * @return list<LeadList>
     */
    public function getLists(): array
    {
        return $this->lists;
    }
}
