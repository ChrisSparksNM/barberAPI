<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HealthController extends Controller
{
    /**
     * Health check endpoint for monitoring
     */
    public function check(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
        ];

        $allHealthy = collect($checks)->every(fn($check) => $check['status'] === 'ok');

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toISOString(),
            'checks' => $checks,
            'version' => config('app.version', '1.0.0'),
        ], $allHealthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            return ['status' => 'ok', 'message' => 'Database connection successful'];
        } catch (\Exception $e) {
            Log::error('Database health check failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Database connection failed'];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . time();
            Cache::put($key, 'test', 10);
            $value = Cache::get($key);
            Cache::forget($key);
            
            if ($value === 'test') {
                return ['status' => 'ok', 'message' => 'Cache is working'];
            }
            
            return ['status' => 'error', 'message' => 'Cache test failed'];
        } catch (\Exception $e) {
            Log::error('Cache health check failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Cache connection failed'];
        }
    }

    private function checkStorage(): array
    {
        try {
            $testFile = storage_path('app/health_check.txt');
            file_put_contents($testFile, 'test');
            $content = file_get_contents($testFile);
            unlink($testFile);
            
            if ($content === 'test') {
                return ['status' => 'ok', 'message' => 'Storage is writable'];
            }
            
            return ['status' => 'error', 'message' => 'Storage test failed'];
        } catch (\Exception $e) {
            Log::error('Storage health check failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Storage not writable'];
        }
    }
}