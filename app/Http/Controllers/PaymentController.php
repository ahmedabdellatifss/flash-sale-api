<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentController extends Controller
{
    protected $service;

    public function __construct(\App\Services\FlashSaleService $service)
    {
        $this->service = $service;
    }

    public function webhook(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'webhook_id' => 'required|string', // Idempotency key
            'order_id' => 'required|integer',
            'status' => 'required|string|in:success,failure',
            'payload' => 'nullable|array',
        ]);

        try {
            $log = $this->service->processPaymentWebhook(
                $request->webhook_id,
                $request->order_id,
                $request->status,
                $request->input('payload')
            );
            return response()->json($log, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
