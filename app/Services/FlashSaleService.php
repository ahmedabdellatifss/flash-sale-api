<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlashSaleService
{
    public function getAvailableStock(Product $product): int
    {
        // For strict correctness, we might want to lock, but for reading, 
        // just reading the column is fine as long as we maintain it correctly.
        return $product->stock_remaining;
    }

    public function createHold(int $productId, int $quantity): array
    {
        return DB::transaction(function () use ($productId, $quantity) {
            // Lock the product row
            $product = Product::lockForUpdate()->find($productId);

            if (!$product) {
                throw new \Exception('Product not found');
            }

            if ($product->stock_remaining < $quantity) {
                throw new \Exception('Insufficient stock');
            }

            // Decrement stock
            $product->stock_remaining -= $quantity;
            $product->save();

            // Create Hold
            $hold = Hold::create([
                'product_id' => $productId,
                'quantity' => $quantity,
                'token' => Str::uuid(),
                'expires_at' => now()->addMinutes(2), // 2 minutes hold
                'status' => 'active',
            ]);

            return [
                'hold_id' => $hold->id,
                'token' => $hold->token,
                'expires_at' => $hold->expires_at,
            ];
        });
    }

    public function convertHoldToOrder(int $holdId): Order
    {
        return DB::transaction(function () use ($holdId) {
            $hold = Hold::lockForUpdate()->find($holdId);

            if (!$hold) {
                throw new \Exception('Invalid hold ID');
            }

            if ($hold->status !== 'active') {
                throw new \Exception('Hold is not active');
            }

            if ($hold->expires_at->isPast()) {
                throw new \Exception('Hold expired');
            }

            // Create Order
            $order = Order::create([
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'quantity' => $hold->quantity,
                'status' => 'pending_payment',
            ]);

            // Mark hold as converted
            $hold->status = 'converted';
            $hold->save();

            return $order;
        });
    }

    public function processPaymentWebhook(string $webhookId, int $orderId, string $status, ?array $payload): PaymentLog
    {
        return DB::transaction(function () use ($webhookId, $orderId, $status, $payload) {
            // Idempotency check
            $existingLog = PaymentLog::where('webhook_id', $webhookId)->first();
            if ($existingLog) {
                return $existingLog;
            }

            $order = Order::lockForUpdate()->find($orderId);
            if (!$order) {
                throw new \Exception('Order not found');
            }

            if ($status === 'success') {
                if ($order->status === 'pending_payment') {
                    $order->status = 'paid';
                    $order->save();
                }
            } else {
                // Payment failed
                if ($order->status === 'pending_payment') {
                    $order->status = 'cancelled';
                    $order->save();

                    // Release stock
                    $product = Product::lockForUpdate()->find($order->product_id);
                    $product->stock_remaining += $order->quantity;
                    $product->save();
                }
            }

            return PaymentLog::create([
                'webhook_id' => $webhookId,
                'order_id' => $orderId,
                'status' => $status,
                'payload' => $payload,
            ]);
        });
    }
}
