<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Repository\CurrencyRepositoryInterface;
use App\Entity\Currency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine ORM implementation of CurrencyRepositoryInterface.
 *
 * @extends ServiceEntityRepository<Currency>
 */
final class DoctrineCurrencyRepository extends ServiceEntityRepository implements CurrencyRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Currency::class);
    }

    public function findByCode(string $code): ?Currency
    {
        // Uses the uniq_currency_code index — single row lookup.
        return $this->createQueryBuilder('c')
            ->where('c.code = :code')
            ->setParameter('code', strtoupper(trim($code)))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Currency[]
     */
    public function findAll(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Currency[]
     */
    public function findAllActive(): array
    {
        // Uses idx_currency_active index.
        return $this->createQueryBuilder('c')
            ->where('c.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('c.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Currency $currency): void
    {
        $em = $this->getEntityManager();
        $em->persist($currency);
        $em->flush();
    }
}
