<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function __invoke(Request $request)
    {
        return response()->json([
            'status'    => 'ok',
            'timestamp' => now()->toIso8601String(),
            'environment' => config('app.env'),
        ]);
    }
}
