<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PageBundle\Event\PageHitEvent;

/**
 * Builds the outbound event payload for {@see \Mautic\PageBundle\PageEvents::PAGE_ON_HIT}.
 *
 * Strategy:
 *   • Root-level keys surface onto the ingest {@code MauticEvent} row columns
 *     ({@code tracking_id}, {@code email_id}, {@code campaign_id}, {@code contact_id},
 *     {@code segment_ids}, {@code channel}, {@code ts}) so they are queryable without JSONB indexing.
 *   • Everything else (url, utm, geo, device, clickthrough, ...) lands in {@code properties}.
 *   • All accessors are guarded with {@code method_exists} / type checks because the phpstan
 *     stubs + the Mautic surface do not always expose the same accessors; graceful degradation
 *     means the subscriber keeps working even when called with a partial Hit stub (tests).
 */
final class PageHitPayloadBuilder
{
    /** Hard cap on individual scalar values that end up in {@code properties} (DoS / PII minimisation). */
    private const SCALAR_VALUE_MAX_LEN = 512;

    /** Hard cap on the passthrough language list. */
    private const MAX_LANGUAGES = 8;

    /** Keys that we explicitly lift out of the Hit query into structured properties; remaining keys are discarded. */
    private const QUERY_UTM_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
    ];

    /** Query keys that are re-surfaced in their own structured sub-objects or discarded (never in {@code custom}). */
    private const QUERY_RESERVED_KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'page_url', 'page_title', 'page_referrer', 'page_language',
        'timezone_offset', 'ct',
    ];

    public function __construct(
        private LeadInsightsContextBuilder $leadContextBuilder,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(PageHitEvent $event): array
    {
        $payload = [
            'channel' => 'page',
            'properties' => [],
        ];

        $hit = $this->hit($event);

        $this->mergeLeadContext($payload, $hit, $event);
        $this->mergeIsUnique($payload, $event);
        $this->mergeHttpCode($payload, $event, $hit);
        $this->mergeHitIdentifiers($payload, $hit, $event);
        $this->mergeHitTime($payload, $hit);
        $this->mergeUrlContext($payload, $hit);
        $this->mergeReferer($payload, $hit);
        $this->mergePageContext($payload, $event, $hit);
        $this->mergeEmailAndRedirect($payload, $hit);
        $this->mergeClickthroughAndSource($payload, $event, $hit);
        $this->mergeUtm($payload, $hit);
        $this->mergeGeoAndNetwork($payload, $hit);
        $this->mergeDevice($payload, $hit);
        $this->mergeLanguages($payload, $hit);
        $this->mergeCustomQuery($payload, $hit);

        if ([] === $payload['properties']) {
            unset($payload['properties']);
        }

        return $payload;
    }

    private function hit(PageHitEvent $event): ?object
    {
        if (!method_exists($event, 'getHit')) {
            return null;
        }
        $hit = $event->getHit();

        return \is_object($hit) ? $hit : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeLeadContext(array &$payload, ?object $hit, PageHitEvent $event): void
    {
        $lead = $this->resolveLead($hit, $event);
        if (!$lead instanceof Lead || $lead->isAnonymous()) {
            return;
        }

        $aug = $this->leadContextBuilder->buildLeadAugmentation($lead);
        $fromLeadProps = $aug['properties'] ?? [];
        unset($aug['properties']);

        $payload = array_merge($payload, $aug);
        if (\is_array($fromLeadProps) && [] !== $fromLeadProps) {
            $payload['properties'] = array_merge($payload['properties'], $fromLeadProps);
        }
    }

    private function resolveLead(?object $hit, PageHitEvent $event): ?Lead
    {
        if (null !== $hit && method_exists($hit, 'getLead')) {
            $lead = $hit->getLead();
            if ($lead instanceof Lead) {
                return $lead;
            }
        }

        if (method_exists($event, 'getLead')) {
            $lead = $event->getLead();
            if ($lead instanceof Lead) {
                return $lead;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeIsUnique(array &$payload, PageHitEvent $event): void
    {
        if (method_exists($event, 'isUnique')) {
            $payload['properties']['is_unique'] = (bool) $event->isUnique();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeHttpCode(array &$payload, PageHitEvent $event, ?object $hit): void
    {
        $code = null;
        if (method_exists($event, 'getCode')) {
            $code = $event->getCode();
        } elseif (null !== $hit && method_exists($hit, 'getCode')) {
            $code = $hit->getCode();
        }
        $int = self::optionalInt($code);
        if (null !== $int) {
            $payload['properties']['http_code'] = $int;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeHitIdentifiers(array &$payload, ?object $hit, PageHitEvent $event): void
    {
        if (null === $hit) {
            return;
        }

        if (method_exists($hit, 'getId')) {
            $hitId = self::optionalInt($hit->getId());
            if (null !== $hitId) {
                $payload['properties']['hit_id'] = $hitId;
            }
        }

        if (method_exists($hit, 'getTrackingId')) {
            $trackingId = self::optionalString($hit->getTrackingId());
            if (null !== $trackingId) {
                $payload['tracking_id'] = $trackingId;
            }
        }

        $payload['properties']['hit_type'] = $this->resolveHitType($hit, $event);
    }

    private function resolveHitType(?object $hit, PageHitEvent $event): string
    {
        if (null !== $hit && method_exists($hit, 'getRedirect')) {
            $redirect = $hit->getRedirect();
            if (\is_object($redirect)) {
                return 'redirect';
            }
        }

        $page = null;
        if (null !== $hit && method_exists($hit, 'getPage')) {
            $page = $hit->getPage();
        }
        if (null === $page && method_exists($event, 'getPage')) {
            $page = $event->getPage();
        }

        return \is_object($page) ? 'landing' : 'external';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeHitTime(array &$payload, ?object $hit): void
    {
        if (null === $hit || !method_exists($hit, 'getDateHit')) {
            return;
        }
        $dateHit = $hit->getDateHit();
        if (!$dateHit instanceof DateTimeInterface) {
            return;
        }
        // Override auto-generated buffer ts with the Hit's recorded timestamp so ordering and
        // the realtime panel bucket (see docs/realtime-event-panel.md §6.1) reflect event time,
        // not flush time.
        $utc = DateTimeImmutable::createFromInterface($dateHit)->setTimezone(new DateTimeZone('UTC'));
        $payload['ts'] = $utc->format('Y-m-d\TH:i:s.u\Z');
        $payload['properties']['date_hit'] = $payload['ts'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeUrlContext(array &$payload, ?object $hit): void
    {
        if (null === $hit) {
            return;
        }

        if (method_exists($hit, 'getUrl')) {
            $url = self::optionalString($hit->getUrl());
            if (null !== $url) {
                $payload['properties']['url'] = $url;
            }
        }

        if (method_exists($hit, 'getUrlTitle')) {
            $title = self::optionalString($hit->getUrlTitle());
            if (null !== $title) {
                $payload['properties']['url_title'] = $title;
            }
        }

        if (method_exists($hit, 'getPageLanguage')) {
            $lang = self::optionalString($hit->getPageLanguage());
            if (null !== $lang) {
                $payload['properties']['page_language'] = $lang;
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeReferer(array &$payload, ?object $hit): void
    {
        if (null === $hit) {
            return;
        }
        $ref = null;
        if (method_exists($hit, 'getReferer')) {
            $ref = self::optionalString($hit->getReferer());
        }
        if (null === $ref) {
            $q = $this->queryArray($hit);
            $pr = $q['page_referrer'] ?? null;
            if (\is_string($pr)) {
                $ref = self::optionalString($pr);
            }
        }
        if (null !== $ref) {
            $payload['properties']['referer'] = $ref;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergePageContext(array &$payload, PageHitEvent $event, ?object $hit): void
    {
        $page = null;
        if (null !== $hit && method_exists($hit, 'getPage')) {
            $page = $hit->getPage();
        }
        if (null === $page && method_exists($event, 'getPage')) {
            $page = $event->getPage();
        }
        if (\is_object($page) && method_exists($page, 'getId')) {
            $pageId = self::optionalInt($page->getId());
            if (null !== $pageId) {
                $payload['properties']['page_id'] = $pageId;
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeEmailAndRedirect(array &$payload, ?object $hit): void
    {
        if (null === $hit) {
            return;
        }

        if (method_exists($hit, 'getEmail')) {
            $email = $hit->getEmail();
            if (\is_object($email) && method_exists($email, 'getId')) {
                $emailId = self::optionalInt($email->getId());
                if (null !== $emailId) {
                    $payload['email_id'] = $emailId;
                    // Email-sourced page hits are marketing clicks, not raw page views.
                    $payload['channel'] = 'email';
                }
            }
        }

        if (method_exists($hit, 'getRedirect')) {
            $redirect = $hit->getRedirect();
            if (\is_object($redirect) && method_exists($redirect, 'getId')) {
                $redirectId = self::optionalInt($redirect->getId());
                if (null !== $redirectId) {
                    $payload['properties']['redirect_id'] = $redirectId;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeClickthroughAndSource(array &$payload, PageHitEvent $event, ?object $hit): void
    {
        $ct = [];
        if (method_exists($event, 'getClickthroughData')) {
            $raw = $event->getClickthroughData();
            if (\is_array($raw)) {
                $ct = $raw;
            }
        }

        $source = null;
        $sourceId = null;
        if (null !== $hit && method_exists($hit, 'getSource')) {
            $source = self::optionalString($hit->getSource());
        }
        if (null !== $hit && method_exists($hit, 'getSourceId')) {
            $sourceId = self::optionalInt($hit->getSourceId());
        }

        if (null !== $source) {
            $payload['properties']['source'] = $source;
        }
        if (null !== $sourceId) {
            $payload['properties']['source_id'] = $sourceId;
        }

        // When the clickthrough says the hit came from a campaign, surface campaign_id as a root column.
        if (isset($ct['channel']) && \is_array($ct['channel'])) {
            $channelPair = $ct['channel'];
            if (1 === \count($channelPair)) {
                $channelName = (string) array_key_first($channelPair);
                $channelValue = self::optionalInt(reset($channelPair));
            } else {
                $channelName = \is_string($channelPair[0] ?? null) ? $channelPair[0] : '';
                $channelValue = self::optionalInt($channelPair[1] ?? null);
            }
            if ('campaign' === $channelName && null !== $channelValue) {
                $payload['campaign_id'] = $channelValue;
            }
        }

        // Keep a small structured echo of the clickthrough so downstream reports can correlate later,
        // but deliberately drop raw tokens (e.g. `lead`, `email`) to avoid leaking identifiers.
        $ctEcho = [];
        foreach (['channel', 'source'] as $k) {
            if (isset($ct[$k])) {
                $ctEcho[$k] = $ct[$k];
            }
        }
        if ([] !== $ctEcho) {
            $payload['properties']['clickthrough'] = $ctEcho;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeUtm(array &$payload, ?object $hit): void
    {
        if (null === $hit) {
            return;
        }
        $query = $this->queryArray($hit);
        $utm = [];
        foreach (self::QUERY_UTM_KEYS as $k) {
            $v = $query[$k] ?? null;
            $s = self::optionalString($v);
            if (null !== $s) {
                $utm[substr($k, 4)] = $s;
            }
        }
        if ([] !== $utm) {
            $payload['properties']['utm'] = $utm;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeGeoAndNetwork(array &$payload, ?object $hit): void
    {
        if (null === $hit) {
            return;
        }
        $geo = [];
        foreach (['country' => 'getCountry', 'region' => 'getRegion', 'city' => 'getCity'] as $key => $method) {
            if (method_exists($hit, $method)) {
                $v = self::optionalString($hit->{$method}());
                if (null !== $v) {
                    $geo[$key] = $v;
                }
            }
        }
        if ([] !== $geo) {
            $payload['properties']['geo'] = $geo;
        }

        $network = [];
        foreach (['isp' => 'getIsp', 'organization' => 'getOrganization'] as $key => $method) {
            if (method_exists($hit, $method)) {
                $v = self::optionalString($hit->{$method}());
                if (null !== $v) {
                    $network[$key] = $v;
                }
            }
        }
        if ([] !== $network) {
            $payload['properties']['network'] = $network;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeDevice(array &$payload, ?object $hit): void
    {
        if (null === $hit || !method_exists($hit, 'getDeviceStat')) {
            return;
        }

        $device = $hit->getDeviceStat();
        if (!\is_object($device)) {
            return;
        }

        $out = [];

        if (method_exists($device, 'getDevice')) {
            $type = self::optionalString($device->getDevice());
            if (null !== $type) {
                $out['type'] = $type;
            }
        }

        $os = [];
        foreach (
            [
                'name' => 'getDeviceOsName',
                'short_name' => 'getDeviceOsShortName',
                'version' => 'getDeviceOsVersion',
                'platform' => 'getDeviceOsPlatform',
            ] as $key => $method
        ) {
            if (method_exists($device, $method)) {
                $v = self::optionalString($device->{$method}());
                if (null !== $v) {
                    $os[$key] = $v;
                }
            }
        }
        if ([] !== $os) {
            $out['os'] = $os;
        }

        foreach (['brand' => 'getDeviceBrand', 'model' => 'getDeviceModel'] as $key => $method) {
            if (method_exists($device, $method)) {
                $v = self::optionalString($device->{$method}());
                if (null !== $v) {
                    $out[$key] = $v;
                }
            }
        }

        if (method_exists($device, 'getClientInfo')) {
            $info = $device->getClientInfo();
            if (\is_array($info)) {
                $browser = [];
                foreach (['name', 'version', 'engine'] as $k) {
                    $v = self::optionalString($info[$k] ?? null);
                    if (null !== $v) {
                        $browser[$k] = $v;
                    }
                }
                if ([] !== $browser) {
                    $out['browser'] = $browser;
                }
            }
        }

        if ([] !== $out) {
            $payload['properties']['device'] = $out;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeLanguages(array &$payload, ?object $hit): void
    {
        if (null === $hit || !method_exists($hit, 'getBrowserLanguages')) {
            return;
        }
        $langs = $hit->getBrowserLanguages();
        if (!\is_array($langs)) {
            return;
        }
        $out = [];
        foreach ($langs as $l) {
            $s = self::optionalString($l);
            if (null === $s) {
                continue;
            }
            $out[] = $s;
            if (\count($out) >= self::MAX_LANGUAGES) {
                break;
            }
        }
        if ([] !== $out) {
            $payload['properties']['languages'] = $out;
        }

        $q = $this->queryArray($hit);
        $tz = $q['timezone_offset'] ?? null;
        $tzInt = self::optionalInt($tz);
        if (null !== $tzInt) {
            $payload['properties']['timezone_offset'] = $tzInt;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeCustomQuery(array &$payload, ?object $hit): void
    {
        $q = $this->queryArray($hit);
        if ([] === $q) {
            return;
        }
        $custom = [];
        foreach ($q as $k => $v) {
            if (!\is_string($k) || '' === $k) {
                continue;
            }
            if (\in_array($k, self::QUERY_RESERVED_KEYS, true)) {
                continue;
            }
            if (\is_scalar($v)) {
                $s = self::optionalString($v);
                if (null !== $s) {
                    $custom[$k] = $s;
                }
            }
        }
        if ([] !== $custom) {
            $payload['properties']['custom'] = $custom;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queryArray(?object $hit): array
    {
        if (null === $hit || !method_exists($hit, 'getQuery')) {
            return [];
        }
        $q = $hit->getQuery();

        return \is_array($q) ? $q : [];
    }

    private static function optionalInt(mixed $v): ?int
    {
        if (null === $v || '' === $v) {
            return null;
        }
        if (\is_int($v)) {
            return $v;
        }
        if (\is_float($v)) {
            return (int) $v;
        }
        if (\is_string($v) && is_numeric($v)) {
            return (int) $v;
        }

        return null;
    }

    private static function optionalString(mixed $v): ?string
    {
        if (null === $v) {
            return null;
        }
        if (\is_string($v)) {
            $t = trim($v);
            if ('' === $t) {
                return null;
            }

            return mb_substr($t, 0, self::SCALAR_VALUE_MAX_LEN);
        }
        if (\is_int($v) || \is_float($v)) {
            return (string) $v;
        }
        if (\is_bool($v)) {
            return $v ? '1' : '0';
        }

        return null;
    }
}
