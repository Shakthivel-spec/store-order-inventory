# Order Creation Flow

This walks through exactly what happens for `POST /api/orders`, step by step, referencing `app/Services/OrderService.php`.

1. **Request arrives** at `OrderController::store()`, type-hinted with `CreateOrderRequest`.
2. **Form Request validates input** — customer name/email present and well-formed, `products` is a non-empty array, every `product_id` exists in the `products` table, every `quantity` is a positive integer. If this fails, Laravel returns `422` automatically and `OrderService` is never reached.
3. **`DB::transaction()` begins.** Everything from here to step 9 either all happens or none of it does.
4. **Customer is found or created** — `Customer::firstOrCreate(['email' => ...], ['name' => ...])`. If the email already exists, that customer row is reused (and its name updated if it changed) rather than creating a duplicate customer.
5. **Requested product lines are grouped by `product_id`** and quantities summed, so ordering the same product twice in one request becomes one combined line instead of two.
6. **Each product row is locked and checked** — `Product::whereKey($id)->lockForUpdate()->first()`, then compared against the requested quantity. If any product doesn't exist or doesn't have enough stock, a `ValidationException` is thrown here, which rolls back the entire transaction — no order, no order items, no stock changes.
7. **Order is created** — one `orders` row with `subtotal`, `tax_amount`, `grand_total` computed from the locked product prices (never from client input).
8. **Order items are created** — one `order_items` row per merged product line, storing a snapshot of the product's name/code/price/tax alongside the computed line subtotal/tax/total.
9. **Stock is deducted** — `Product::decrement('stock_on_hand', $quantity)` for each line, still inside the same transaction and still holding the row lock acquired in step 6.
10. **Transaction commits.** All of the above becomes permanent at once.
11. **Confirmation job is dispatched** — `SendOrderConfirmationJob::dispatch($order->id)->afterCommit()`. Because of `afterCommit()`, this only actually fires once step 10 has succeeded — see `docs/concurrency.md` and the README's "Queue design" section for why that matters.
12. **Response is returned** — `201` with the created order, customer, and line items.

## What happens if a step fails

Any exception thrown between steps 3 and 9 (product not found, insufficient stock, a database error) causes `DB::transaction()` to roll back automatically. Nothing partial is left behind: no order row, no order-item rows, and stock is back to whatever it was before the request — because it was never actually committed in the first place. The HTTP response in that case is `422` with a message describing what failed.

## Why a transaction is necessary

An order touches four things that must all succeed together: the customer, the order row, one or more order-item rows, and stock on one or more products. If any of these were done outside a transaction and a later step failed, you could end up with stock deducted but no order recorded, or an order recorded with no items. Wrapping the whole operation in `DB::transaction()` guarantees it's all-or-nothing.
