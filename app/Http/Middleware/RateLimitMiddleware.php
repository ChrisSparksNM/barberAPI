<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $key = 'global'): Response
    {
        $identifier = $request->ip();
        
        // Different limits for different endpoints
        $limits = [
            'login' => [5, 60], // 5 attempts per minute
            'payment' => [10, 60], // 10 payment attempts per minute
            'booking' => [20, 60], // 20 booking attempts per minute
            'global' => [60, 60], // 60 requests per minute
        ];

        [$maxAttempts, $decayMinutes] = $limits[$key] ?? $limits['global'];

        if (RateLimiter::tooManyAttempts($key . ':' . $identifier, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key . ':' . $identifier);
            
            return response()->json([
                'error' => 'Too many requests. Please try again later.',
                'retry_after' => $seconds
            ], 429);
        }

        RateLimiter::hit($key . ':' . $identifier, $decayMinutes * 60);

        return $next($request);
    }
}