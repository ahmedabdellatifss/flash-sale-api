<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ReleaseExpiredHolds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'holds:release-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release expired holds and return stock to products';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = 0;

        // Process in chunks to avoid memory issues
        \App\Models\Hold::where('status', 'active')
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($holds) use (&$count) {
                foreach ($holds as $hold) {
                    try {
                        \Illuminate\Support\Facades\DB::transaction(function () use ($hold) {
                            // Lock hold to ensure it's still active and expired
                            $hold = \App\Models\Hold::lockForUpdate()->find($hold->id);

                            if ($hold->status !== 'active' || $hold->expires_at > now()) {
                                return;
                            }

                            // Mark as expired
                            $hold->status = 'expired';
                            $hold->save();

                            // Return stock
                            $product = \App\Models\Product::lockForUpdate()->find($hold->product_id);
                            if ($product) {
                                $product->stock_remaining += $hold->quantity;
                                $product->save();
                            }
                        });
                        $count++;
                    } catch (\Exception $e) {
                        $this->error("Failed to release hold {$hold->id}: " . $e->getMessage());
                    }
                }
            });

        $this->info("Released {$count} expired holds.");
    }
}
