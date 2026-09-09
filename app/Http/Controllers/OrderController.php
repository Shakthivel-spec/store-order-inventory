<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateOrderRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService)
    {
    }

    public function store(CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->createOrder($request->validated());

        return response()->json([
            'message' => 'Order created successfully.',
            'data' => $this->formatOrder($order),
        ], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $customer = Customer::query()
            ->where('email', strtolower(trim($validated['email'])))
            ->first();

        if (! $customer) {
            return response()->json([
                'message' => 'No customer found for this email.',
            ], 404);
        }

        $orders = $customer->orders()
            ->with('items')
            ->latest()
            ->get()
            ->each(fn (Order $order) => $order->setRelation('customer', $customer))
            ->map(fn (Order $order) => $this->formatOrder($order))
            ->values();

        return response()->json([
            'data' => $orders,
        ]);
    }

    private function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'customer' => [
                'name' => $order->customer->name,
                'email' => $order->customer->email,
            ],
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'grand_total' => (float) $order->grand_total,
            'created_at' => $order->created_at?->toISOString(),
            'items' => $order->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'unit_price' => (float) $item->unit_price,
                'tax_percentage' => (float) $item->tax_percentage,
                'quantity' => $item->quantity,
                'line_subtotal' => (float) $item->line_subtotal,
                'line_tax' => (float) $item->line_tax,
                'line_total' => (float) $item->line_total,
            ])->values()->all(),
        ];
    }
}
