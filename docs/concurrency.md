# Concurrency & Overselling Protection

## The race condition without locking

Imagine a product has `stock_on_hand = 1`, and two customers place an order for it at nearly the same instant.

Without any locking, both requests would run something like:

```
Request A: SELECT stock_on_hand FROM products WHERE id = 4   -> reads 1
Request B: SELECT stock_on_hand FROM products WHERE id = 4   -> reads 1
Request A: 1 >= 1, so proceed. UPDATE stock_on_hand = 0
Request B: 1 >= 1, so proceed. UPDATE stock_on_hand = 0   (or worse, -1, depending on how the decrement is written)
```

Both requests read the same "1 unit available" snapshot before either of them had written anything back. Both concluded the order was valid. Both created an order for a unit of stock that only existed once. This is the classic **check-then-act race condition**, and it's the exact scenario requirement #7 in the assignment describes.

## Why a normal stock check isn't enough

A plain `if ($product->stock_on_hand >= $quantity)` check in application code is not atomic with the database write that follows it. Between the `SELECT` and the `UPDATE`, another request can run its own `SELECT` and see the same stale value. The check and the deduction have to be made atomic *with respect to other transactions*, and that's not something PHP-level logic can do on its own — it requires the database.

## Why `lockForUpdate()` is used

`app/Services/OrderService.php` does this for every requested product, inside a `DB::transaction()`:

```php
$product = Product::query()
    ->whereKey($productId)
    ->lockForUpdate()
    ->first();
```

`lockForUpdate()` issues a `SELECT ... FOR UPDATE`. This tells MySQL: "lock this row for writing, and make any other transaction that also wants to lock this row wait until I commit or roll back." Concretely:

- Request A's transaction acquires the lock on product row 4, reads `stock_on_hand = 1`.
- Request B's transaction also wants to read/lock product row 4 — it **blocks** at that `SELECT ... FOR UPDATE` line, doing nothing, until Request A finishes.
- Request A checks `1 >= 1`, proceeds, decrements stock to `0`, and commits.
- Only now does Request B's lock get granted. It reads the *current, post-commit* value: `stock_on_hand = 0`.
- Request B checks `0 >= 1` — fails — and the transaction rolls back with a validation error. No order is created for Request B.

The key property: Request B is guaranteed to see Request A's result before making its own decision, because it physically cannot proceed past the lock until Request A's transaction ends.

## Why the transaction is required in addition to the lock

The lock by itself only blocks other transactions from reading/writing that row concurrently — it doesn't guarantee that the check and the deduction happen together as one unit. If the stock check and the `decrement()` were in separate transactions, the lock from the first transaction would already be released by the time the second one ran, reopening the race window. Wrapping the check, the order/order-item creation, and the deduction in one `DB::transaction()` means the lock is held continuously from the first `SELECT ... FOR UPDATE` until everything commits.

## What this implementation guarantees

- Exactly one of two simultaneous requests for the last unit of a product succeeds.
- The other fails cleanly with a `422` and a clear message — no order or order items are created for it, and stock is untouched by the failed request.
- `stock_on_hand` never goes negative, because the check against the *locked* (i.e. current) value always happens before any decrement.
- Requested product IDs are sorted before locks are acquired (`->sortKeys()` on the grouped products), so that when an order spans multiple products, every concurrent request acquires locks in the same order — this avoids a classic multi-row deadlock where two transactions each hold one lock and wait on the other's.

## How this was actually verified

The automated test suite includes `test_a_second_order_cannot_oversell_the_last_unit`, which issues two sequential HTTP requests against a SQLite in-memory database and asserts only one order is created and stock lands at 0, never negative. This proves the check-then-deduct *logic* is correct.

However, SQLite does not have MySQL-style multi-connection row-level locking, so a sequential test alone cannot prove that concurrent requests actually block on the database the way described above. To verify that specifically, two real, separately-connected PHP processes were run against a live MySQL database, deliberately racing for `lockForUpdate()` on the same product row (one process held the lock for 1.5 seconds to force an overlap). The result: the second process's lock acquisition was measurably delayed until the first committed, and it then correctly observed the reduced stock and failed — confirming the blocking behavior is real, not just logically correct in isolation. This manual verification isn't part of the automated suite because PHPUnit's test client runs single-threaded against one connection and isn't suited to driving genuinely parallel database transactions.
