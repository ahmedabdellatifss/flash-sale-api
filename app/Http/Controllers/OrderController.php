<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class OrderController extends Controller
{
    protected $service;

    public function __construct(\App\Services\FlashSaleService $service)
    {
        $this->service = $service;
    }

    public function store(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'hold_id' => 'required|integer',
        ]);

        try {
            $order = $this->service->convertHoldToOrder($request->hold_id);
            return response()->json($order, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
