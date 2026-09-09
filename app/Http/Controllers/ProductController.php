<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Product::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'price', 'tax_percentage', 'stock_on_hand']),
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'threshold' => ['sometimes', 'integer', 'min:0'],
        ]);

        $threshold = (int) ($validated['threshold'] ?? config('inventory.low_stock_threshold'));

        return response()->json([
            'threshold' => $threshold,
            'data' => Product::query()
                ->where('stock_on_hand', '<=', $threshold)
                ->orderBy('stock_on_hand')
                ->get(['id', 'name', 'code', 'price', 'tax_percentage', 'stock_on_hand']),
        ]);
    }
}
