<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sync\PancakeCatalogSyncService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(private PancakeCatalogSyncService $syncService)
    {
    }

    public function status()
    {
        return $this->successResponse($this->syncService->status());
    }

    public function sync(Request $request)
    {
        $request->validate([
            'entity' => 'nullable|string|in:categories,products,vouchers,orders',
        ]);

        $result = $this->syncService->sync($request->input('entity'));
        $status = $result['status'] === 'success' ? 200 : 207;

        return $this->successResponse($result, 'Sync finished', $status);
    }
}
