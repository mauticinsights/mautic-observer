<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contact counts for POST …/status: identified = date_identified IS NOT NULL; anonymous = IS NULL.
 *
 * @see \Mautic\LeadBundle\Entity\LeadRepository::addSearchCommandWhereClause() is:anonymous
 */
class MauticLeadCountsForInsightsStatus
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function countIdentified(): int
    {
        return $this->countWhereDateIdentified(true);
    }

    public function countAnonymous(): int
    {
        return $this->countWhereDateIdentified(false);
    }

    private function countWhereDateIdentified(bool $identified): int
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(l.id)')
                ->from(Lead::class, 'l');
            if ($identified) {
                $qb->where($qb->expr()->isNotNull('l.dateIdentified'));
            } else {
                $qb->where($qb->expr()->isNull('l.dateIdentified'));
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (Throwable $e) {
            $this->logger->warning(
                'Mautic Insights: could not count leads for status: '.$e->getMessage(),
                ['exception' => $e]
            );

            return 0;
        }
    }
}
