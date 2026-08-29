<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiOrigin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Allow health checks and debugging tools in local environment
        if (app()->environment('local', 'testing')) {
            return $next($request);
        }

        if ($request->is('api/health')) {
            return $next($request);
        }

        if ($request->is('api/v1/payment/momo/ipn')) {
            return $next($request);
        }

        if ($request->is('api/v1/payment/sepay/webhook')) {
            return $next($request);
        }

        // 2. Define allowed patterns (normalized to lowercase)
        $allowedOrigins = [
            'teesik.store',
            'www.teesik.store',
            'localhost',
            '127.0.0.1',
            'app',
            'teesik-app',
        ];

        $appUrlHost = parse_url(config('app.url'), PHP_URL_HOST);
        if ($appUrlHost) {
            $allowedOrigins[] = strtolower($appUrlHost);
        }

        $isAllowedHost = function (string $host) use ($allowedOrigins): bool {
            foreach ($allowedOrigins as $allowed) {
                if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                    return true;
                }
            }

            return false;
        };

        // 3. Check Origin header (Browser requests)
        $origin = $request->headers->get('Origin');
        if ($origin) {
            $parsedOrigin = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');
            if ($parsedOrigin && !$isAllowedHost($parsedOrigin)) {
                \Log::warning('Blocked Origin: ' . $parsedOrigin . ' (Full: ' . $origin . ')');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized origin.',
                    'origin' => $parsedOrigin
                ], 403);
            }
            return $next($request);
        }

        // 4. Check Referer header (Fallback for some scenarios)
        $referer = $request->headers->get('Referer');
        if ($referer) {
            $parsedReferer = strtolower(parse_url($referer, PHP_URL_HOST) ?? '');
            if ($parsedReferer && !$isAllowedHost($parsedReferer)) {
                \Log::warning('Blocked Referer: ' . $parsedReferer . ' (Full: ' . $referer . ')');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized referer.'
                ], 403);
            }
            return $next($request);
        }

        return $next($request);
    }
}
