<?php

namespace App\Repository;

use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Find transactions by type
     */
    public function findByType(string $type): array
    {
        return $this->findBy(['transactionType' => $type], ['transactionDate' => 'DESC']);
    }

    /**
     * Get transactions for date range
     */
    public function findInDateRange(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate
    ): array {
        return $this->createQueryBuilder('t')
            ->where('t.transactionDate >= :start')
            ->andWhere('t.transactionDate <= :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->orderBy('t.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get failed transactions
     */
    public function findFailed(): array
    {
        return $this->findBy(['status' => 'failed'], ['transactionDate' => 'DESC']);
    }

    /**
     * Get pending transactions (might be stuck)
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => 'pending'], ['transactionDate' => 'ASC']);
    }

    /**
     * Calculate total volume for date range
     */
    public function getTotalVolumeInDateRange(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        string $type = null
    ): string {
        $qb = $this->createQueryBuilder('t')
            ->select('SUM(ABS(t.netAmount))')
            ->where('t.transactionDate >= :start')
            ->andWhere('t.transactionDate <= :end')
            ->andWhere('t.status = :status')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', 'completed');
        
        if ($type) {
            $qb->andWhere('t.transactionType = :type')
               ->setParameter('type', $type);
        }
        
        $result = $qb->getQuery()->getSingleScalarResult();
        return $result ?? '0.00';
    }
}