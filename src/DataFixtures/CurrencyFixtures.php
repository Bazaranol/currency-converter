<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Currency;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeds the currencies table with a predefined set of ISO 4217 currencies.
 *
 * Idempotent: if a currency with the same code already exists, it is
 * updated (name, symbol, active status) rather than duplicated.
 *
 * Usage:
 *   php bin/console doctrine:fixtures:load               # purge + load
 *   php bin/console doctrine:fixtures:load --append       # add without purge
 */
final class CurrencyFixtures extends Fixture
{
    /**
     * Each entry: [code, name, symbol].
     * Only currencies supported by freecurrencyapi.com (free tier).
     * Full list: https://freecurrencyapi.com/docs/currency-list
     *
     * @var list<array{string, string, string}>
     */
    private const CURRENCIES = [
        ['USD', 'US Dollar',          '$'],
        ['EUR', 'Euro',               '€'],
        ['GBP', 'British Pound',      '£'],
        ['JPY', 'Japanese Yen',       '¥'],
        ['CHF', 'Swiss Franc',        'Fr'],
        ['CAD', 'Canadian Dollar',    'C$'],
        ['AUD', 'Australian Dollar',  'A$'],
        ['CNY', 'Chinese Yuan',       '¥'],
        ['INR', 'Indian Rupee',       '₹'],
        ['PLN', 'Polish Zloty',       'zł'],
        ['RUB', 'Russian Ruble',      '₽'],
        ['TRY', 'Turkish Lira',       '₺'],
        ['BRL', 'Brazilian Real',      'R$'],
        ['SEK', 'Swedish Krona',      'kr'],
        ['NOK', 'Norwegian Krone',    'kr'],
        ['NZD', 'New Zealand Dollar', 'NZ$'],
        ['MXN', 'Mexican Peso',       'Mex$'],
    ];

    public function load(ObjectManager $manager): void
    {
        $repository = $manager->getRepository(Currency::class);
        $created = 0;
        $updated = 0;

        foreach (self::CURRENCIES as [$code, $name, $symbol]) {
            /** @var Currency|null $existing */
            $existing = $repository->findOneBy(['code' => $code]);

            if ($existing !== null) {
                $existing->rename($name);
                $existing->updateSymbol($symbol);
                $existing->activate();
                $updated++;
            } else {
                $currency = new Currency(
                    code: $code,
                    name: $name,
                    symbol: $symbol,
                    isActive: true,
                );
                $manager->persist($currency);
                $created++;

                // Store reference for other fixtures that might need Currency entities.
                $this->addReference('currency_' . $code, $currency);
            }
        }

        $manager->flush();

        // Output isn't available in fixtures, but this is useful for debugging.
        // Created: $created, Updated: $updated
    }
}