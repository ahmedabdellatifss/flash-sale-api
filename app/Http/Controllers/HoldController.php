<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HoldController extends Controller
{
    protected $service;

    public function __construct(\App\Services\FlashSaleService $service)
    {
        $this->service = $service;
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer',
            'qty' => 'required|integer|min:1',
        ]);

        try {
            $result = $this->service->createHold($request->product_id, $request->qty);
            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
