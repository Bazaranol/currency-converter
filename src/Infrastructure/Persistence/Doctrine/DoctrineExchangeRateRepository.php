<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Repository\ExchangeRateRepositoryInterface;
use App\Entity\Currency;
use App\Entity\ExchangeRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine ORM implementation of ExchangeRateRepositoryInterface.
 *
 * Query strategy:
 *   - findLatestRate()       : idx_rate_pair_date (covering index for pair + ORDER BY fetchedAt DESC)
 *   - findAllLatestRates()   : subquery with MAX(fetched_at) grouped by pair, JOIN-fetches both currencies
 *   - findRatesByDate()      : idx_rate_fetched (range scan on date)
 *   - saveMany()             : batch insert with flush every 50 rows + clear() to cap memory
 *
 * @extends ServiceEntityRepository<ExchangeRate>
 */
final class DoctrineExchangeRateRepository extends ServiceEntityRepository implements ExchangeRateRepositoryInterface
{
    /** Number of entities to accumulate before flushing in saveMany(). */
    private const BATCH_SIZE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeRate::class);
    }

    // ── Single pair lookup ──────────────────────────────

    public function findLatestRate(Currency $base, Currency $target): ?ExchangeRate
    {
        /*
         * Uses idx_rate_pair_date (base_currency_id, target_currency_id, fetched_at)
         * which covers the WHERE + ORDER BY in a single index scan.
         * JOIN FETCH prevents lazy-loading N+1 on Currency relations.
         */
        return $this->createQueryBuilder('er')
            ->join('er.baseCurrency', 'bc')
            ->join('er.targetCurrency', 'tc')
            ->addSelect('bc', 'tc')
            ->where('er.baseCurrency = :base')
            ->andWhere('er.targetCurrency = :target')
            ->setParameter('base', $base)
            ->setParameter('target', $target)
            ->orderBy('er.fetchedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // ── All latest rates ────────────────────────────────

    /**
     * Returns one row per currency pair — the row with the most recent fetchedAt.
     *
     * Strategy: a correlated subquery finds MAX(fetched_at) for each
     * (base_currency_id, target_currency_id) group, then the outer query
     * matches on all three columns (which hits idx_rate_unique).
     *
     * JOIN FETCH on both currencies eliminates N+1 entirely —
     * everything comes back in a single SQL query.
     *
     * @return ExchangeRate[]
     */
    public function findAllLatestRates(): array
    {
        /*
         * DQL equivalent of:
         *
         *   SELECT er.*
         *   FROM exchange_rates er
         *   WHERE er.fetched_at = (
         *       SELECT MAX(sub.fetched_at)
         *       FROM exchange_rates sub
         *       WHERE sub.base_currency_id = er.base_currency_id
         *         AND sub.target_currency_id = er.target_currency_id
         *   )
         *
         * The subquery is correlated, but MySQL optimizes it well
         * with the idx_rate_pair_date composite index.
         */
        return $this->createQueryBuilder('er')
            ->join('er.baseCurrency', 'bc')
            ->join('er.targetCurrency', 'tc')
            ->addSelect('bc', 'tc')
            ->where(
                'er.fetchedAt = (
                    SELECT MAX(sub.fetchedAt)
                    FROM App\Entity\ExchangeRate sub
                    WHERE sub.baseCurrency = er.baseCurrency
                      AND sub.targetCurrency = er.targetCurrency
                )',
            )
            ->orderBy('bc.code', 'ASC')
            ->addOrderBy('tc.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // ── Rates by date ───────────────────────────────────

    /**
     * @return ExchangeRate[]
     */
    public function findRatesByDate(\DateTimeInterface $date): array
    {
        $dayStart = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);
        $dayEnd = $dayStart->setTime(23, 59, 59);

        return $this->createQueryBuilder('er')
            ->join('er.baseCurrency', 'bc')
            ->join('er.targetCurrency', 'tc')
            ->addSelect('bc', 'tc')
            ->where('er.fetchedAt BETWEEN :start AND :end')
            ->setParameter('start', $dayStart)
            ->setParameter('end', $dayEnd)
            ->orderBy('bc.code', 'ASC')
            ->addOrderBy('tc.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // ── Persistence ─────────────────────────────────────

    public function save(ExchangeRate $rate): void
    {
        $em = $this->getEntityManager();
        $em->persist($rate);
        $em->flush();
    }

    /**
     * Batch-persist multiple exchange rates efficiently.
     *
     * Flushes every BATCH_SIZE entities to limit memory and DB lock time.
     * Only clears the identity map at the very end, after all entities
     * are persisted, to avoid detaching Currency relations mid-batch.
     *
     * @param ExchangeRate[] $rates
     */
    public function saveMany(array $rates): void
    {
        if (count($rates) === 0) {
            return;
        }

        $em = $this->getEntityManager();

        foreach ($rates as $i => $rate) {
            $em->persist($rate);

            // Flush every BATCH_SIZE rows to limit memory pressure.
            if (($i + 1) % self::BATCH_SIZE === 0) {
                $em->flush();
            }
        }

        // Flush remaining entities that didn't hit the batch boundary.
        $em->flush();

        // Clear the identity map after everything is persisted.
        $em->clear();
    }

    // ── Metadata ────────────────────────────────────────

    public function findLatestFetchedAt(): ?\DateTimeImmutable
    {
        /** @var string|null $result */
        $result = $this->createQueryBuilder('er')
            ->select('MAX(er.fetchedAt)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($result === null) {
            return null;
        }

        return new \DateTimeImmutable($result);
    }
}
