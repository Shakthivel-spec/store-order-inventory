<?php

namespace App\Services;

use App\Jobs\SendOrderConfirmationJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function createOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $customer = Customer::query()->firstOrCreate(
                ['email' => strtolower(trim($data['customer']['email']))],
                ['name' => trim($data['customer']['name'])],
            );

            if ($customer->name !== trim($data['customer']['name'])) {
                $customer->update(['name' => trim($data['customer']['name'])]);
            }

            $requestedItems = collect($data['products'])
                ->groupBy('product_id')
                ->map(fn ($items) => (int) $items->sum('quantity'))
                ->sortKeys();

            $lockedProducts = [];

            foreach ($requestedItems as $productId => $quantity) {
                /** @var Product $product */
                $product = Product::query()
                    ->whereKey($productId)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'products' => ["Product {$productId} no longer exists."],
                    ]);
                }

                if ($product->stock_on_hand < $quantity) {
                    throw ValidationException::withMessages([
                        'products' => [
                            "Insufficient stock for {$product->name}. Available: {$product->stock_on_hand}, requested: {$quantity}.",
                        ],
                    ]);
                }

                $lockedProducts[$product->id] = $product;
            }

            $subtotal = 0.0;
            $taxAmount = 0.0;
            $lineItems = [];

            foreach ($requestedItems as $productId => $quantity) {
                $product = $lockedProducts[$productId];
                $lineSubtotal = round((float) $product->price * $quantity, 2);
                $lineTax = round($lineSubtotal * ((float) $product->tax_percentage / 100), 2);
                $lineTotal = round($lineSubtotal + $lineTax, 2);

                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;

                $lineItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'line_subtotal' => $lineSubtotal,
                    'line_tax' => $lineTax,
                    'line_total' => $lineTotal,
                ];
            }

            $order = Order::query()->create([
                'customer_id' => $customer->id,
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($taxAmount, 2),
                'grand_total' => round($subtotal + $taxAmount, 2),
            ]);

            foreach ($lineItems as $line) {
                /** @var Product $product */
                $product = $line['product'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'unit_price' => $product->price,
                    'tax_percentage' => $product->tax_percentage,
                    'quantity' => $line['quantity'],
                    'line_subtotal' => $line['line_subtotal'],
                    'line_tax' => $line['line_tax'],
                    'line_total' => $line['line_total'],
                ]);

                $product->decrement('stock_on_hand', $line['quantity']);
            }

            $order->load(['customer', 'items.product']);

            // afterCommit() guarantees the job only runs once this transaction commits,
            // so a queue worker never picks up a job for an order it can't see yet.
            SendOrderConfirmationJob::dispatch($order->id)->afterCommit();

            return $order;
        });
    }
}
