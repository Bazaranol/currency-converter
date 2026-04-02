<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Infrastructure\Persistence\Doctrine\DoctrineExchangeRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for DoctrineExchangeRateRepository.
 *
 * Requires a running test database. Uses the Symfony kernel to get
 * real Doctrine services — this tests actual SQL, not mocks.
 *
 * Run:
 *   make shell
 *   php bin/console doctrine:database:create --env=test --if-not-exists
 *   php bin/console doctrine:migrations:migrate --env=test --no-interaction
 *   php bin/phpunit --testsuite=integration
 */
final class ExchangeRateRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DoctrineExchangeRateRepository $repository;

    private Currency $usd;
    private Currency $eur;
    private Currency $gbp;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(DoctrineExchangeRateRepository::class);

        // Clean tables for a fresh start.
        $this->em->getConnection()->executeStatement('DELETE FROM exchange_rates');
        $this->em->getConnection()->executeStatement('DELETE FROM currencies');
        $this->em->clear();

        // Create test currencies.
        $this->usd = new Currency('USD', 'US Dollar', '$');
        $this->eur = new Currency('EUR', 'Euro', '€');
        $this->gbp = new Currency('GBP', 'British Pound', '£');

        $this->em->persist($this->usd);
        $this->em->persist($this->eur);
        $this->em->persist($this->gbp);
        $this->em->flush();
    }

    public function test_save_and_find_latest_rate(): void
    {
        $older = new ExchangeRate(
            $this->usd, $this->eur,
            '0.9100000000',
            new \DateTimeImmutable('2025-01-01 10:00:00'),
        );
        $newer = new ExchangeRate(
            $this->usd, $this->eur,
            '0.9200000000',
            new \DateTimeImmutable('2025-01-02 10:00:00'),
        );

        $this->repository->save($older);
        $this->repository->save($newer);
        $this->em->clear();

        // Reload entities after clear.
        $usd = $this->em->getRepository(Currency::class)->findOneBy(['code' => 'USD']);
        $eur = $this->em->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);

        $latest = $this->repository->findLatestRate($usd, $eur);

        self::assertNotNull($latest);
        self::assertSame('0.9200000000', $latest->getRate());
    }

    public function test_find_latest_rate_returns_null_when_no_rates(): void
    {
        $result = $this->repository->findLatestRate($this->usd, $this->eur);

        self::assertNull($result);
    }

    public function test_find_all_latest_rates_returns_one_per_pair(): void
    {
        // USD→EUR: two historical records.
        $this->repository->save(new ExchangeRate(
            $this->usd, $this->eur,
            '0.9100000000',
            new \DateTimeImmutable('2025-01-01 10:00:00'),
        ));
        $this->repository->save(new ExchangeRate(
            $this->usd, $this->eur,
            '0.9200000000',
            new \DateTimeImmutable('2025-01-02 10:00:00'),
        ));

        // USD→GBP: one record.
        $this->repository->save(new ExchangeRate(
            $this->usd, $this->gbp,
            '0.7900000000',
            new \DateTimeImmutable('2025-01-02 10:00:00'),
        ));

        $this->em->clear();

        $allLatest = $this->repository->findAllLatestRates();

        // Should return 2 pairs: USD/EUR (latest) and USD/GBP.
        self::assertCount(2, $allLatest);

        $ratesByPair = [];
        foreach ($allLatest as $rate) {
            $ratesByPair[$rate->getPairCode()] = $rate->getRate();
        }

        self::assertSame('0.9200000000', $ratesByPair['USD/EUR']);
        self::assertSame('0.7900000000', $ratesByPair['USD/GBP']);
    }

    public function test_save_many_batch_insert(): void
    {
        $rates = [];
        $fetchedAt = new \DateTimeImmutable();

        for ($i = 1; $i <= 75; $i++) {
            $rates[] = new ExchangeRate(
                $this->usd,
                $this->eur,
                bcadd('0.9', (string) ($i / 10000), 10),
                $fetchedAt->modify("+{$i} seconds"),
            );
        }

        $this->repository->saveMany($rates);
        $this->em->clear();

        // Verify all 75 rows were persisted.
        $count = (int) $this->em->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM exchange_rates')
            ->fetchOne();

        self::assertSame(75, $count);
    }

    public function test_find_latest_fetched_at(): void
    {
        $this->repository->save(new ExchangeRate(
            $this->usd, $this->eur,
            '0.9100000000',
            new \DateTimeImmutable('2025-03-15 08:00:00'),
        ));
        $this->repository->save(new ExchangeRate(
            $this->usd, $this->gbp,
            '0.7900000000',
            new \DateTimeImmutable('2025-03-16 12:30:00'),
        ));

        $this->em->clear();

        $latest = $this->repository->findLatestFetchedAt();

        self::assertNotNull($latest);
        self::assertSame('2025-03-16', $latest->format('Y-m-d'));
    }

    public function test_find_latest_fetched_at_returns_null_when_empty(): void
    {
        $result = $this->repository->findLatestFetchedAt();

        self::assertNull($result);
    }

    public function test_find_rates_by_date(): void
    {
        $this->repository->save(new ExchangeRate(
            $this->usd, $this->eur,
            '0.9100000000',
            new \DateTimeImmutable('2025-03-15 10:00:00'),
        ));
        $this->repository->save(new ExchangeRate(
            $this->usd, $this->eur,
            '0.9200000000',
            new \DateTimeImmutable('2025-03-16 10:00:00'),
        ));

        $this->em->clear();

        $rates = $this->repository->findRatesByDate(new \DateTimeImmutable('2025-03-15'));

        self::assertCount(1, $rates);
        self::assertSame('0.9100000000', $rates[0]->getRate());
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM exchange_rates');
        $this->em->getConnection()->executeStatement('DELETE FROM currencies');

        parent::tearDown();
    }
}
