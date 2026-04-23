<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\LeadBundle\Entity\Lead;

/**
 * Builds the outbound {@code mautic.form_on_submit} payload: form and submission metadata for
 * analytics in Insights (funnels, referrers, field completion). Raw field values from
 * {@see \Mautic\FormBundle\Entity\Submission::getResults()} are intentionally omitted — we only
 * emit field aliases and counts to reduce PII surface; contact fields still come from the
 * {@see Lead} entity when the submitter is known (same as core Mautic lead columns).
 */
final class FormSubmitPayloadBuilder
{
    private const MAX_REFERER_LEN = 512;

    public function __construct(
        private LeadInsightsContextBuilder $leadContextBuilder,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(SubmissionEvent $event): array
    {
        $submission = $event->getSubmission();
        $properties = [];

        if (method_exists($submission, 'getForm') && null !== $submission->getForm()) {
            $form = $submission->getForm();
            if (method_exists($form, 'getId') && null !== $form->getId()) {
                $properties['form_id'] = (int) $form->getId();
            }
            if (method_exists($form, 'getName') && null !== $form->getName()) {
                $name = (string) $form->getName();
                if ('' !== $name) {
                    $properties['form_name'] = $name;
                }
            }
            if (method_exists($form, 'getAlias') && null !== $form->getAlias()) {
                $alias = (string) $form->getAlias();
                if ('' !== $alias) {
                    $properties['form_alias'] = $alias;
                }
            }
        }

        if (method_exists($submission, 'getId')) {
            $rawId = $submission->getId();
            if (null !== $rawId && (is_int($rawId) || (is_string($rawId) && is_numeric($rawId)))) {
                $properties['submission_id'] = (int) $rawId;
            }
        }

        if (method_exists($submission, 'getDateSubmitted')) {
            $d = $submission->getDateSubmitted();
            if ($d instanceof DateTimeInterface) {
                $utc = new DateTimeZone('UTC');
                $immutable = $d instanceof DateTimeImmutable
                    ? $d->setTimezone($utc)
                    : DateTimeImmutable::createFromInterface($d)->setTimezone($utc);
                $properties['submitted_at'] = $immutable->format(\DATE_ATOM);
            }
        }

        if (method_exists($submission, 'getReferer')) {
            $ref = (string) $submission->getReferer();
            if ('' !== $ref) {
                $properties['referer'] = mb_substr($ref, 0, self::MAX_REFERER_LEN);
            }
        }

        if (method_exists($submission, 'getPage') && null !== $submission->getPage()) {
            $page = $submission->getPage();
            if (\is_object($page) && method_exists($page, 'getId') && null !== $page->getId()) {
                $properties['page_id'] = (int) $page->getId();
            }
            if (\is_object($page) && method_exists($page, 'getTitle') && null !== $page->getTitle()) {
                $properties['page_title'] = (string) $page->getTitle();
            }
        }

        $this->mergeResultFieldMetadata($submission, $properties);

        $payload = [
            'channel' => 'form',
            'properties' => $properties,
        ];

        if (method_exists($submission, 'getTrackingId')) {
            $tid = $submission->getTrackingId();
            if (null !== $tid) {
                $s = (string) $tid;
                if ('' !== $s) {
                    $payload['tracking_id'] = $s;
                }
            }
        }

        if (method_exists($submission, 'getLead') && null !== $submission->getLead()) {
            $lead = $submission->getLead();
            if ($lead instanceof Lead && !$lead->isAnonymous()) {
                $aug = $this->leadContextBuilder->buildLeadAugmentation($lead);
                $fromLeadProps = $aug['properties'] ?? [];
                if (!\is_array($fromLeadProps)) {
                    $fromLeadProps = [];
                }
                unset($aug['properties']);
                $payload = array_merge($payload, $aug);
                if ([] !== $fromLeadProps) {
                    $payload['properties'] = array_merge($payload['properties'] ?? [], $fromLeadProps);
                }
            }
        }

        return $payload;
    }

    /**
     * Mautic core stores {@see \Mautic\FormBundle\Entity\Submission::getResults()} as
     * {@code field_alias => submitted_value}. We only emit sorted aliases, never values.
     *
     * @param array<string, mixed> $properties
     */
    private function mergeResultFieldMetadata(object $submission, array &$properties): void
    {
        if (!method_exists($submission, 'getResults')) {
            return;
        }

        $results = $submission->getResults();
        if (!\is_array($results) || [] === $results) {
            return;
        }

        $aliases = [];
        foreach (array_keys($results) as $k) {
            if (\is_string($k) || \is_int($k)) {
                $aliases[] = (string) $k;
            }
        }
        $aliases = array_values(array_unique($aliases));
        sort($aliases, SORT_STRING);
        $properties['field_count'] = \count($results);
        if ([] !== $aliases) {
            $properties['field_aliases'] = $aliases;
        }
    }
}
