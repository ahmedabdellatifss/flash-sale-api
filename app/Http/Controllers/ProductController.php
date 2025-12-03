<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProductController extends Controller
{
    protected $service;

    public function __construct(\App\Services\FlashSaleService $service)
    {
        $this->service = $service;
    }

    public function show($id)
    {
        $product = \App\Models\Product::findOrFail($id);
        // Ensure we get the latest stock from DB if not already fresh, 
        // but findOrFail usually gets it. 
        // We want to explicitly show available stock.

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            'available_stock' => $this->service->getAvailableStock($product),
        ]);
    }
}
