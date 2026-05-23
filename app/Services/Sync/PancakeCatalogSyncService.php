<?php

namespace App\Services\Sync;

use App\Models\DataSyncRun;
use App\Models\DataSyncState;
use App\Repositories\LocalOrderRepository;
use App\Repositories\LocalProductRepository;
use App\Repositories\LocalVoucherRepository;
use App\Services\Pancake\PancakeMarketingService;
use App\Services\Pancake\PancakeOrderService;
use App\Services\Pancake\PancakeProductService;
use App\Services\VoucherService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class PancakeCatalogSyncService
{
    public function __construct(
        private PancakeProductService $productService,
        private PancakeMarketingService $marketingService,
        private PancakeOrderService $orderService,
        private LocalProductRepository $products,
        private LocalOrderRepository $orders,
        private LocalVoucherRepository $vouchers,
        private VoucherService $voucherService
    ) {
    }

    public function sync(?string $entity = null): array
    {
        $entities = $entity ? [$entity] : ['categories', 'products', 'vouchers', 'orders'];
        $results = [];
        $hasFailure = false;

        foreach ($entities as $syncEntity) {
            $result = match ($syncEntity) {
                'categories' => $this->syncCategories(),
                'products' => $this->syncProducts(),
                'vouchers' => $this->syncVouchers(),
                'orders' => $this->syncOrders(),
                default => [
                    'entity' => $syncEntity,
                    'status' => 'failed',
                    'error' => 'Unsupported sync entity.',
                ],
            };

            if (($result['status'] ?? 'failed') !== 'success') {
                $hasFailure = true;
            }

            $results[] = $result;
        }

        return [
            'status' => $hasFailure ? 'partial_failed' : 'success',
            'results' => $results,
        ];
    }

    public function status(): array
    {
        $states = DataSyncState::query()
            ->where('source', 'pancake')
            ->orderBy('entity')
            ->get()
            ->map(fn(DataSyncState $state) => [
                'entity' => $state->entity,
                'status' => $state->status,
                'last_synced_at' => $state->last_synced_at?->toISOString(),
                'last_started_at' => $state->last_started_at?->toISOString(),
                'last_finished_at' => $state->last_finished_at?->toISOString(),
                'last_records_synced' => $state->last_records_synced,
                'last_error' => $state->last_error,
                'freshness' => $this->freshness($state),
            ])
            ->values();

        $runs = DataSyncRun::query()
            ->where('source', 'pancake')
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn(DataSyncRun $run) => [
                'id' => $run->id,
                'entity' => $run->entity,
                'status' => $run->status,
                'started_at' => $run->started_at?->toISOString(),
                'finished_at' => $run->finished_at?->toISOString(),
                'fetched_count' => $run->fetched_count,
                'upserted_count' => $run->upserted_count,
                'error' => $run->error,
                'logs' => $run->logs ?? [],
            ])
            ->values();

        return [
            'states' => $states,
            'runs' => $runs,
        ];
    }

    private function syncCategories(): array
    {
        return $this->runEntity('categories', function () {
            $categories = $this->productService->getCategories();
            if (!is_iterable($categories)) {
                throw new \UnexpectedValueException('Invalid category response format.');
            }

            $upserted = 0;
            foreach ($categories as $category) {
                $this->products->upsertCategory((array) $category);
                $upserted++;
            }

            return ['fetched' => $upserted, 'upserted' => $upserted];
        });
    }

    private function syncProducts(): array
    {
        return $this->runEntity('products', function () {
            $paginator = $this->productService->getProducts(1, 1000);
            $items = $paginator->items();
            if (!is_array($items) && !$items instanceof \Traversable) {
                throw new \UnexpectedValueException('Invalid product response format.');
            }

            $fetched = 0;
            $upserted = 0;

            foreach ($items as $product) {
                $product = (array) $product;
                if (!isset($product['id'])) {
                    throw new \UnexpectedValueException('Product response item is missing id.');
                }

                if (!empty($product['category'])) {
                    $this->products->upsertCategory((array) $product['category']);
                }

                $this->products->upsertProduct($product);
                $fetched++;
                $upserted++;
            }

            return ['fetched' => $fetched, 'upserted' => $upserted];
        });
    }

    private function syncVouchers(): array
    {
        return $this->runEntity('vouchers', function () {
            $paginator = $this->marketingService->getVouchers(1, 500);
            $items = $paginator->items();
            if (!is_array($items) && !$items instanceof \Traversable) {
                throw new \UnexpectedValueException('Invalid voucher response format.');
            }

            $fetched = 0;
            $upserted = 0;

            foreach ($items as $rawVoucher) {
                $normalized = $this->voucherService->normalizeVoucher((array) $rawVoucher);
                if (empty($normalized['code'])) {
                    throw new \UnexpectedValueException('Voucher response item is missing code.');
                }

                $this->vouchers->upsert($normalized);
                $fetched++;
                $upserted++;
            }

            return ['fetched' => $fetched, 'upserted' => $upserted];
        });
    }

    private function syncOrders(): array
    {
        return $this->runEntity('orders', function () {
            $paginator = $this->orderService->getOrders(1, 1000);
            $items = $paginator->items();
            if (!is_array($items) && !$items instanceof \Traversable) {
                throw new \UnexpectedValueException('Invalid order response format.');
            }

            $fetched = 0;
            $upserted = 0;

            foreach ($items as $order) {
                $order = (array) $order;
                if (!isset($order['id'])) {
                    throw new \UnexpectedValueException('Order response item is missing id.');
                }

                $this->orders->upsertFromPancake($order);
                $fetched++;
                $upserted++;
            }

            return ['fetched' => $fetched, 'upserted' => $upserted];
        });
    }

    private function runEntity(string $entity, callable $callback): array
    {
        $state = DataSyncState::updateOrCreate(
            ['source' => 'pancake', 'entity' => $entity],
            [
                'status' => 'running',
                'last_started_at' => now(),
                'last_error' => null,
            ]
        );

        $run = DataSyncRun::create([
            'source' => 'pancake',
            'entity' => $entity,
            'status' => 'running',
            'started_at' => now(),
            'logs' => [],
        ]);

        try {
            $result = $callback();
            $finishedAt = now();

            $run->update([
                'status' => 'success',
                'finished_at' => $finishedAt,
                'fetched_count' => $result['fetched'] ?? 0,
                'upserted_count' => $result['upserted'] ?? 0,
                'logs' => ['Sync completed.'],
            ]);

            $state->update([
                'status' => 'success',
                'last_synced_at' => $finishedAt,
                'last_finished_at' => $finishedAt,
                'last_records_synced' => $result['upserted'] ?? 0,
                'last_error' => null,
            ]);

            return [
                'entity' => $entity,
                'status' => 'success',
                'fetched_count' => $result['fetched'] ?? 0,
                'upserted_count' => $result['upserted'] ?? 0,
                'last_synced_at' => $finishedAt->toISOString(),
            ];
        } catch (\Throwable $e) {
            $message = $this->classifyError($e);
            $finishedAt = now();

            Log::error("Pancake {$entity} sync failed: {$message}", ['exception' => $e]);

            $run->update([
                'status' => 'failed',
                'finished_at' => $finishedAt,
                'error' => $message,
                'logs' => [$message],
            ]);

            $state->update([
                'status' => 'failed',
                'last_finished_at' => $finishedAt,
                'last_error' => $message,
            ]);

            return [
                'entity' => $entity,
                'status' => 'failed',
                'error' => $message,
            ];
        }
    }

    private function classifyError(\Throwable $e): string
    {
        $message = $e->getMessage();
        $lower = strtolower($message);

        if ($e instanceof ConnectionException || str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return 'Pancake API timeout or network connection failed.';
        }

        if (str_contains($lower, '429') || str_contains($lower, 'rate')) {
            return 'Pancake API rate limit reached.';
        }

        if ($e instanceof \UnexpectedValueException || str_contains($lower, 'invalid') || str_contains($lower, 'missing')) {
            return 'Pancake API returned an invalid response format: ' . $message;
        }

        return $message ?: 'Unknown Pancake sync error.';
    }

    private function freshness(DataSyncState $state): string
    {
        if (!$state->last_synced_at) {
            return 'never_synced';
        }

        if ($state->last_synced_at->lt(now()->subDay())) {
            return 'stale';
        }

        return 'fresh';
    }
}
