<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Event;

use Mautic\FormBundle\Entity\Submission;

class SubmissionEvent
{
    public function __construct(
        private Submission $submission,
    ) {
    }

    public function getSubmission(): Submission
    {
        return $this->submission;
    }
}
