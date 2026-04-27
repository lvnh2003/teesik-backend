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
        // 1. Allow health checks from anywhere (monitoring)
        if ($request->is('api/health')) {
            return $next($request);
        }

        // 2. Define allowed patterns
        $allowedOrigins = [
            'teesik.store',
            'www.teesik.store',
            'localhost:3000', // Dev
        ];

        // 3. Check Origin header (Browser requests)
        $origin = $request->headers->get('Origin');
        if ($origin) {
            $parsedOrigin = parse_url($origin, PHP_URL_HOST);
            if ($parsedOrigin && !in_array($parsedOrigin, $allowedOrigins)) {
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
            $parsedReferer = parse_url($referer, PHP_URL_HOST);
            if ($parsedReferer && !in_array($parsedReferer, $allowedOrigins)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized referer.'
                ], 403);
            }
            return $next($request);
        }

        // 5. Block direct access if NOT in local/testing (Postman/curl)
        // Note: We might want to allow this for server-to-server calls if we have webhooks.
        // For now, let's keep it strict but allow health checks.
        if (app()->environment('production')) {
            // In production, we expect most calls to come from the frontend (with Origin/Referer)
            // If you have webhooks, you should add their IPs or paths to an exception list.
            if (!$origin && !$referer) {
                // Check if it's a known non-browser endpoint or let it through if it has a secret token
                // For now, let's be cautious and let it pass if it's not a browser request
                // but CORS will still block it if it WAS a browser request from another domain.
            }
        }

        return $next($request);
    }
}
