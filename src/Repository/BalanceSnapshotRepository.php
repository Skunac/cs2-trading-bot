<?php

namespace App\Repository;

use App\Entity\BalanceSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BalanceSnapshot>
 */
class BalanceSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BalanceSnapshot::class);
    }

    /**
     * Get most recent snapshot
     */
    public function findLatest(): ?BalanceSnapshot
    {
        return $this->findOneBy([], ['snapshotDate' => 'DESC']);
    }

    /**
     * Get snapshots for date range
     */
    public function findInDateRange(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate
    ): array {
        return $this->createQueryBuilder('b')
            ->where('b.snapshotDate >= :start')
            ->andWhere('b.snapshotDate <= :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->orderBy('b.snapshotDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get snapshots for last N days
     */
    public function findLastNDays(int $days = 7): array
    {
        $startDate = new \DateTimeImmutable("-{$days} days");
        
        return $this->createQueryBuilder('b')
            ->where('b.snapshotDate >= :start')
            ->setParameter('start', $startDate)
            ->orderBy('b.snapshotDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete old snapshots (cleanup)
     */
    public function deleteOlderThan(\DateTimeInterface $date): int
    {
        return $this->createQueryBuilder('b')
            ->delete()
            ->where('b.snapshotDate < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}