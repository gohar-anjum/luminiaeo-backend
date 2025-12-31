<?php

namespace App\Console\Commands;

use App\Models\LocationCode;
use App\Services\DataForSEO\DataForSEOService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncLocationCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'location-codes:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync location codes from DataForSEO API to database';

    /**
     * Execute the console command.
     */
    public function handle(DataForSEOService $dataForSEOService): int
    {
        $this->info('Fetching location codes from DataForSEO API...');

        try {
            $locationCodes = $dataForSEOService->getLocationCodes();

            if (empty($locationCodes)) {
                $this->error('No location codes received from API');
                return Command::FAILURE;
            }

            $this->info("Received " . count($locationCodes) . " location codes");

            $this->info('Storing location codes in database...');

            DB::transaction(function () use ($locationCodes) {
                // Clear existing data (optional - comment out if you want to keep historical data)
                // LocationCode::truncate();

                $bar = $this->output->createProgressBar(count($locationCodes));
                $bar->start();

                foreach ($locationCodes as $locationData) {
                    LocationCode::updateOrCreate(
                        ['location_code' => $locationData['location_code']],
                        [
                            'location_name' => $locationData['location_name'] ?? '',
                            'location_code_parent' => $locationData['location_code_parent'] ?? null,
                            'country_iso_code' => $locationData['country_iso_code'] ?? null,
                            'location_type' => $locationData['location_type'] ?? null,
                        ]
                    );
                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
            });

            $totalCount = LocationCode::count();
            $countriesCount = LocationCode::countries()->count();

            // Clear all location codes cache after syncing
            $this->info('Clearing location codes cache...');
            
            // Clear specific cache keys
            // Note: Paginated results will expire naturally, but we clear the most common ones
            Cache::forget('location_codes:countries');
            Cache::forget('dataforseo:location_codes:google:ads_search');
            
            // If using Redis, we can clear by pattern (optional optimization)
            try {
                if (config('cache.default') === 'redis' && method_exists(Cache::store(), 'getRedis')) {
                    $redis = Cache::store()->getRedis();
                    $prefix = config('cache.prefix', '');
                    $pattern = $prefix . 'location_codes:*';
                    $keys = $redis->keys($pattern);
                    if (!empty($keys)) {
                        foreach ($keys as $key) {
                            $redis->del($key);
                        }
                        $this->info('Cleared ' . count($keys) . ' cache keys from Redis');
                    }
                }
            } catch (\Exception $e) {
                // Silently fail if Redis pattern clearing doesn't work
                // Cache will expire naturally
            }
            
            $this->info('Cache cleared successfully!');

            $this->info("Successfully synced location codes!");
            $this->info("Total locations: {$totalCount}");
            $this->info("Countries: {$countriesCount}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to sync location codes: ' . $e->getMessage());
            try {
                Log::error('Location codes sync failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors, just output to console
            }
            return Command::FAILURE;
        }
    }
}
