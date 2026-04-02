<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Repository\CurrencyRepositoryInterface;
use App\Domain\Repository\ExchangeRateRepositoryInterface;
use App\Service\ExchangeRateSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Fetches the latest exchange rates from an external API and persists them.
 *
 * Designed to run daily via cron. Uses a filesystem lock to prevent
 * parallel execution, an "already synced today" guard, and a dry-run
 * mode for safe testing.
 *
 * Cron setup (host):
 *   0 6 * * * cd /var/www/html && php bin/console app:sync-exchange-rates >> var/log/cron.log 2>&1
 *
 * Cron setup (Docker):
 *   0 6 * * * docker compose exec -T app php bin/console app:sync-exchange-rates >> /var/log/cron.log 2>&1
 *
 * Exit codes:
 *   0 — success (rates updated)
 *   1 — failure (API error or unexpected exception)
 *   2 — skipped (rates already updated today, use --force to override)
 *   3 — locked (another instance is already running)
 */
#[AsCommand(
    name: 'app:sync-exchange-rates',
    description: 'Fetch and update exchange rates from external API',
)]
final class SyncExchangeRatesCommand extends Command
{
    private const EXIT_LOCKED = 3;

    public function __construct(
        private readonly ExchangeRateSyncService $syncService,
        private readonly ExchangeRateRepositoryInterface $exchangeRateRepository,
        private readonly CurrencyRepositoryInterface $currencyRepository,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'base-currency',
                'b',
                InputOption::VALUE_REQUIRED,
                'Base currency for fetching rates (ISO 4217)',
                'USD',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force update even if rates were already synced today',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be updated without persisting',
            )
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command fetches the latest exchange rates
                from the configured external API and saves them to the database.

                <info>Basic usage (daily cron):</info>
                  %command.full_name%

                <info>Force re-sync even if rates exist for today:</info>
                  %command.full_name% --force

                <info>Preview without saving:</info>
                  %command.full_name% --dry-run

                <info>Use a different base currency:</info>
                  %command.full_name% --base-currency=EUR

                The command uses a filesystem lock to prevent parallel execution.
                If another instance is already running, it exits immediately.
                HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $baseCurrency = strtoupper(trim((string) $input->getOption('base-currency')));

        $io->title('Exchange Rate Synchronization');

        // ── Acquire lock to prevent parallel runs ────
        $lock = $this->lockFactory->createLock('sync-exchange-rates', ttl: 300);

        if (!$lock->acquire()) {
            $io->warning('Another sync process is already running. Skipping.');

            return self::EXIT_LOCKED;
        }

        try {
            return $this->doExecute($io, $output, $force, $dryRun, $baseCurrency);
        } finally {
            $lock->release();
        }
    }

    private function doExecute(
        SymfonyStyle $io,
        OutputInterface $output,
        bool $force,
        bool $dryRun,
        string $baseCurrency,
    ): int {
        if ($dryRun) {
            $io->note('DRY-RUN mode — no data will be persisted.');
        }

        // ── Guard: already synced today? ────────────
        if (!$force && $this->wasSyncedToday()) {
            $io->warning('Rates were already synced today. Use --force to override.');

            return 2;
        }

        // ── Dry-run: show what would be synced ──────
        if ($dryRun) {
            return $this->executeDryRun($io, $baseCurrency);
        }

        // ── Run actual sync ─────────────────────────
        $io->section('Fetching rates from API...');

        try {
            $result = $this->syncService->sync($baseCurrency);
        } catch (\Throwable $e) {
            $io->error([
                'Sync failed with an unexpected error:',
                $e->getMessage(),
            ]);

            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }

            return Command::FAILURE;
        }

        // ── Output results ──────────────────────────
        if ($result->errorCount > 0 && $result->updatedCount === 0) {
            $io->error('Sync failed completely. No rates were updated.');
            $this->printErrors($io, $result->errors);

            return Command::FAILURE;
        }

        $io->section('Results');

        $io->definitionList(
            ['Base currency' => $baseCurrency],
            ['Updated' => (string) $result->updatedCount],
            ['Skipped' => (string) $result->skippedCount],
            ['Errors' => (string) $result->errorCount],
            ['Synced at' => $result->syncedAt->format('Y-m-d H:i:s T')],
        );

        if ($result->errorCount > 0) {
            $io->warning('Some rates failed to sync:');
            $this->printErrors($io, $result->errors);
        }

        $io->success(sprintf(
            'Successfully synced %d exchange rate(s).',
            $result->updatedCount,
        ));

        return Command::SUCCESS;
    }

    /**
     * Dry-run: display list of currencies that would be synced.
     */
    private function executeDryRun(SymfonyStyle $io, string $baseCurrency): int
    {
        $currencies = $this->currencyRepository->findAllActive();

        if (\count($currencies) === 0) {
            $io->warning('No active currencies found.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Would sync rates for %d currencies (base: %s)', \count($currencies), $baseCurrency));

        $rows = [];
        foreach ($currencies as $currency) {
            if ($currency->getCode() === $baseCurrency) {
                continue;
            }
            $rows[] = [$baseCurrency, $currency->getCode(), $currency->getName()];
        }

        $io->table(['Base', 'Target', 'Name'], $rows);
        $io->note(sprintf('DRY-RUN complete. %d pairs would be fetched. Nothing was saved.', \count($rows)));

        return Command::SUCCESS;
    }

    private function wasSyncedToday(): bool
    {
        $latestFetchedAt = $this->exchangeRateRepository->findLatestFetchedAt();

        if ($latestFetchedAt === null) {
            return false;
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        return $latestFetchedAt >= $today;
    }

    /**
     * @param string[] $errors
     */
    private function printErrors(SymfonyStyle $io, array $errors): void
    {
        foreach ($errors as $error) {
            $io->text(sprintf('  • %s', $error));
        }
    }
}
