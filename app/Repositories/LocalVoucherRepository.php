<?php

namespace App\Repositories;

use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

class LocalVoucherRepository
{
    public function all()
    {
        return Voucher::query()
            ->orderBy('code')
            ->get()
            ->map(fn(Voucher $voucher) => $voucher->toApiArray())
            ->values();
    }

    public function active()
    {
        $now = now();

        return Voucher::query()
            ->where('is_activated', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('start_date')->orWhere('start_date', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $now);
            })
            ->where(function ($query) {
                $query->where('usage_limit', 0)->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->orderBy('code')
            ->get()
            ->map(fn(Voucher $voucher) => $voucher->toApiArray())
            ->values();
    }

    public function paginate(int $page = 1, int $limit = 30): LengthAwarePaginator
    {
        $paginator = Voucher::query()->orderBy('code')->paginate($limit, ['*'], 'page', $page);

        return new LengthAwarePaginator(
            $paginator->getCollection()->map(fn(Voucher $voucher) => $voucher->toApiArray())->values(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function upsert(array $voucher): Voucher
    {
        $code = trim((string) ($voucher['code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('Voucher is missing code.');
        }

        $raw = $voucher['raw'] ?? $voucher;
        $pancakeId = $raw['id'] ?? $voucher['id'] ?? null;

        return Voucher::updateOrCreate(
            ['code' => $code],
            [
                'pancake_id' => $pancakeId ? (string) $pancakeId : null,
                'name' => $voucher['name'] ?? $code,
                'description' => $voucher['description'] ?? '',
                'is_activated' => (bool) ($voucher['is_activated'] ?? true),
                'is_percent' => (bool) ($voucher['is_percent'] ?? false),
                'discount_value' => (float) ($voucher['discount_value'] ?? 0),
                'max_discount' => (float) ($voucher['max_discount'] ?? 0),
                'min_order_value' => (float) ($voucher['min_order_value'] ?? 0),
                'usage_limit' => (int) ($voucher['usage_limit'] ?? 0),
                'used_count' => (int) ($voucher['used_count'] ?? 0),
                'start_date' => $this->parseDate($voucher['start_date'] ?? null),
                'end_date' => $this->parseDate($voucher['end_date'] ?? null),
                'data' => $raw,
                'synced_at' => now(),
            ]
        );
    }

    private function parseDate($value)
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value);
    }
}
