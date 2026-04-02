<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Repository\CurrencyRepositoryInterface;
use App\Entity\Currency;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists all currencies registered in the system.
 *
 * Usage:
 *   php bin/console app:currencies:list              # all currencies
 *   php bin/console app:currencies:list --active      # active only
 *   php bin/console app:currencies:list --inactive    # inactive only
 */
#[AsCommand(
    name: 'app:currencies:list',
    description: 'Display all registered currencies',
)]
final class ListCurrenciesCommand extends Command
{
    public function __construct(
        private readonly CurrencyRepositoryInterface $currencyRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'active',
                'a',
                InputOption::VALUE_NONE,
                'Show only active currencies',
            )
            ->addOption(
                'inactive',
                'i',
                InputOption::VALUE_NONE,
                'Show only inactive currencies',
            )
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command displays a table of all currencies
                stored in the database.

                <info>Show all currencies:</info>
                  %command.full_name%

                <info>Filter by status:</info>
                  %command.full_name% --active
                  %command.full_name% --inactive
                HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $showActive = (bool) $input->getOption('active');
        $showInactive = (bool) $input->getOption('inactive');

        if ($showActive && $showInactive) {
            $io->error('Options --active and --inactive are mutually exclusive.');
            return Command::INVALID;
        }

        // Fetch currencies.
        $currencies = $this->fetchCurrencies($showActive, $showInactive);

        if (count($currencies) === 0) {
            $io->warning('No currencies found. Run fixtures first: php bin/console doctrine:fixtures:load');
            return Command::SUCCESS;
        }

        // Build table.
        $io->title('Registered Currencies');

        $rows = array_map(
            fn (Currency $c): array => [
                $c->getCode(),
                $c->getName(),
                $c->getSymbol(),
                $c->isActive() ? '<fg=green>✓ active</>' : '<fg=red>✗ inactive</>',
                $c->getCreatedAt()->format('Y-m-d H:i'),
            ],
            $currencies,
        );

        $io->table(
            ['Code', 'Name', 'Symbol', 'Status', 'Created'],
            $rows,
        );

        // Summary.
        $activeCount = count(array_filter($currencies, fn (Currency $c): bool => $c->isActive()));
        $inactiveCount = count($currencies) - $activeCount;

        $io->text(sprintf(
            'Total: %d currencies (%d active, %d inactive)',
            count($currencies),
            $activeCount,
            $inactiveCount,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return Currency[]
     */
    private function fetchCurrencies(bool $activeOnly, bool $inactiveOnly): array
    {
        if ($activeOnly) {
            return $this->currencyRepository->findAllActive();
        }

        $all = $this->currencyRepository->findAll();

        if ($inactiveOnly) {
            return array_values(
                array_filter($all, fn (Currency $c): bool => !$c->isActive()),
            );
        }

        return $all;
    }
}
