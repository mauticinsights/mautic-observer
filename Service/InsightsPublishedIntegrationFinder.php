<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;

/**
 * Loads the published {@see Integration} row for MauticInsights.
 *
 * Deliberately bypasses Mautic's {@see \Mautic\CoreBundle\Cache\ResultCacheHelper} ORM result cache:
 * that cache is keyed on namespace `IntegrationSettings` and is only cleared on `postUpdate` of the
 * `Integration` entity (i.e. when the config form is saved). On every other request (contact create/
 * delete, page hit, …) the cache would return a stale or null result, causing {@see MauticEventBuffer}
 * to fall back to the persistent buffer instead of sending events immediately.
 */
final class InsightsPublishedIntegrationFinder
{
    /** @var Integration|null used only when constructed via {@see createForTest} */
    private ?Integration $fixedIntegration = null;

    public function __construct(
        private ?EntityManagerInterface $entityManager = null,
    ) {
    }

    /**
     * Test-only factory: returns a finder that always yields $integration without touching the DB.
     */
    public static function createForTest(Integration $integration): self
    {
        $instance = new self(null);
        $instance->fixedIntegration = $integration;

        return $instance;
    }

    /**
     * @return list<string>
     */
    public static function integrationNamesToTry(): array
    {
        return array_values(array_unique([
            InsightsApiConfig::NAME,
            strtolower(InsightsApiConfig::NAME),
        ]));
    }

    public function findPublished(): ?Integration
    {
        if (null !== $this->fixedIntegration) {
            return $this->fixedIntegration->getIsPublished() ? $this->fixedIntegration : null;
        }

        foreach (self::integrationNamesToTry() as $name) {
            $integration = $this->findByNameNoCache($name);
            if (null !== $integration && $integration->getIsPublished()) {
                return $integration;
            }
        }

        return null;
    }

    private function findByNameNoCache(string $name): ?Integration
    {
        if (null === $this->entityManager) {
            return null;
        }

        $result = $this->entityManager
            ->createQuery('SELECT i FROM '.Integration::class.' i WHERE i.name = :name')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->useQueryCache(false)
            ->getOneOrNullResult();

        return $result instanceof Integration ? $result : null;
    }
}
