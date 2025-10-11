<?php

namespace App\Repository;

use App\Entity\Alert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Alert>
 */
class AlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alert::class);
    }

    /**
     * Find unresolved alerts
     */
    public function findUnresolved(): array
    {
        return $this->findBy(['resolved' => false], ['createdAt' => 'DESC']);
    }

    /**
     * Find critical unresolved alerts
     */
    public function findCriticalUnresolved(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.resolved = :resolved')
            ->andWhere('a.severity = :severity')
            ->setParameter('resolved', false)
            ->setParameter('severity', 'critical')
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find alerts by type
     */
    public function findByType(string $type, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.alertType = :type')
            ->setParameter('type', $type)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find alerts by severity
     */
    public function findBySeverity(string $severity): array
    {
        return $this->findBy(['severity' => $severity], ['createdAt' => 'DESC']);
    }

    /**
     * Find recent alerts
     */
    public function findRecent(int $hours = 24): array
    {
        $cutoffDate = new \DateTimeImmutable("-{$hours} hours");
        
        return $this->createQueryBuilder('a')
            ->where('a.createdAt >= :cutoff')
            ->setParameter('cutoff', $cutoffDate)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count unresolved by severity
     */
    public function countUnresolvedBySeverity(): array
    {
        $results = $this->createQueryBuilder('a')
            ->select('a.severity, COUNT(a.id) as count')
            ->where('a.resolved = :resolved')
            ->setParameter('resolved', false)
            ->groupBy('a.severity')
            ->getQuery()
            ->getResult();
        
        $counts = [
            'low' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0,
        ];
        
        foreach ($results as $result) {
            $counts[$result['severity']] = (int) $result['count'];
        }
        
        return $counts;
    }

    /**
     * Delete old resolved alerts (cleanup)
     */
    public function deleteResolvedOlderThan(\DateTimeInterface $date): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->where('a.resolved = :resolved')
            ->andWhere('a.createdAt < :date')
            ->setParameter('resolved', true)
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}