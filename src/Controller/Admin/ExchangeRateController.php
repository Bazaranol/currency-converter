<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Repository\CurrencyRepositoryInterface;
use App\Domain\Repository\ExchangeRateRepositoryInterface;
use App\Exception\ConversionException;
use App\Exception\CurrencyNotFoundException;
use App\Exception\RateNotFoundException;
use App\Service\CurrencyConverterInterface;
use App\Service\ExchangeRateSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/exchange-rates', name: 'admin_exchange_rates_')]
final class ExchangeRateController extends AbstractController
{
    /** Minimum interval between syncs triggered from the UI (seconds). */
    private const SYNC_COOLDOWN_SECONDS = 300;

    public function __construct(
        private readonly ExchangeRateRepositoryInterface $exchangeRateRepository,
        private readonly CurrencyRepositoryInterface $currencyRepository,
        private readonly CurrencyConverterInterface $converter,
        private readonly ExchangeRateSyncService $syncService,
        private readonly LockFactory $lockFactory,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $rates = $this->exchangeRateRepository->findAllLatestRates();
        $currencies = $this->currencyRepository->findAllActive();
        $lastSyncedAt = $this->exchangeRateRepository->findLatestFetchedAt();

        return $this->render('admin/exchange_rate/index.html.twig', [
            'rates' => $rates,
            'currencies' => $currencies,
            'lastSyncedAt' => $lastSyncedAt,
            'totalPairs' => count($rates),
        ]);
    }

    #[Route('/convert', name: 'convert', methods: ['POST'])]
    public function convert(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $amount = $data['amount'] ?? null;
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;

        if ($amount === null || $from === null || $to === null) {
            return $this->json(
                ['error' => 'Missing required fields: amount, from, to.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (!is_numeric($amount)) {
            return $this->json(['error' => 'Amount must be numeric.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->converter->convert((string) $amount, (string) $from, (string) $to);

            return $this->json([
                'success' => true,
                'originalAmount' => $result->originalAmount,
                'originalCurrency' => $result->originalCurrency,
                'convertedAmount' => $result->convertedAmount,
                'targetCurrency' => $result->targetCurrency,
                'rate' => $result->rate,
                'convertedAt' => $result->convertedAt->format('Y-m-d H:i:s'),
            ]);
        } catch (ConversionException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (CurrencyNotFoundException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (RateNotFoundException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/sync', name: 'sync', methods: ['POST'])]
    public function sync(): JsonResponse
    {
        // ── Cooldown: prevent rapid repeated syncs ───
        $lastSync = $this->exchangeRateRepository->findLatestFetchedAt();

        if ($lastSync !== null) {
            $secondsSinceLastSync = time() - $lastSync->getTimestamp();
            if ($secondsSinceLastSync < self::SYNC_COOLDOWN_SECONDS) {
                $waitSeconds = self::SYNC_COOLDOWN_SECONDS - $secondsSinceLastSync;

                return $this->json(
                    ['error' => sprintf('Rates were synced recently. Try again in %d seconds.', $waitSeconds)],
                    Response::HTTP_TOO_MANY_REQUESTS,
                );
            }
        }

        // ── Lock: prevent concurrent syncs ──────────
        $lock = $this->lockFactory->createLock('sync-exchange-rates', ttl: 300);

        if (!$lock->acquire()) {
            return $this->json(
                ['error' => 'Another sync is already in progress.'],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $result = $this->syncService->sync();

            return $this->json([
                'success' => true,
                'updated' => $result->updatedCount,
                'skipped' => $result->skippedCount,
                'errors' => $result->errorCount,
                'syncedAt' => $result->syncedAt->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return $this->json(
                ['error' => 'Sync failed: ' . $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        } finally {
            $lock->release();
        }
    }
}
