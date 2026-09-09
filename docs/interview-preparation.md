# Interview Preparation

Answers grounded in this project's actual code. File references point at where to look if asked to show it live.

**Why these four tables — products, customers, orders, order_items?**
Products and customers are independent entities. Orders link one customer to one or more products, and since an order can have multiple product lines, a join table (`order_items`) is required to represent that many-to-many relationship with per-line data attached (quantity, line totals).

**Why is `order_items` needed instead of a `product_id` column on `orders`?**
An order isn't limited to one product — the assignment requires "one or more product lines." A single foreign key on `orders` can only point at one product. `order_items` also snapshots the product's name/price/tax at purchase time, so historical orders stay accurate even if the catalog changes later — see `docs/database.md`.

**Why use Eloquent relationships instead of manual joins?**
They express the domain model directly in code (`$customer->orders`, `$order->items`, `$item->product`), keep query logic (like eager loading with `with()`) consistent and testable, and avoid hand-written SQL for straightforward foreign-key relationships.

**Why a Form Request instead of validating in the controller?**
`CreateOrderRequest` (`app/Http/Requests/CreateOrderRequest.php`) separates "is this input well-formed" from "is this business-valid" and "what does this business rule mean." It keeps the controller from being cluttered with a `$request->validate([...])` block, and Laravel automatically returns a `422` if it fails, before the controller method even runs.

**Why should the controller stay thin?**
`OrderController::store()` is three lines: call the service, format the response. Business logic (stock locking, tax math, transaction boundaries) belongs somewhere it can be unit-tested and reasoned about independently of HTTP concerns — that's `OrderService`. A fat controller mixes concerns and is harder to test without spinning up a full HTTP request.

**Why a Service instead of putting logic in the model or a Repository?**
Order creation orchestrates multiple models (Customer, Product, Order, OrderItem) and a transaction boundary — that doesn't belong to any single model. A Repository pattern was deliberately not introduced: there's one data store, Eloquent already provides a clean enough API, and adding an interface/repository layer with only one implementation is indirection without benefit for a project this size.

**How is subtotal calculated?**
Per line: `unit_price × quantity`, using the product's *locked*, current price — never anything from the request. The order's `subtotal` is the sum of all line subtotals.

**How is tax calculated?**
Per line: `line_subtotal × tax_percentage / 100`, rounded to 2 decimal places. The order's `tax_amount` is the sum of all line tax values.

**Why calculate totals on the backend instead of trusting the frontend's numbers?**
The UI does compute live totals client-side for responsiveness, but the request only ever sends `product_id` and `quantity` — see `CreateOrderRequest`'s rules. Price and tax are always read from the database inside the transaction. A malicious or buggy client sending a fabricated price is simply ignored; I verified this directly by sending a request with a spoofed `"price": 0.01` field and confirming the server charged the real catalog price.

**How is stock deducted?**
`Product::decrement('stock_on_hand', $quantity)`, inside the same transaction as the stock check, after the order and order-item rows are created — see `docs/order-flow.md`.

**Why `DB::transaction()`?**
Because an order touches four things that must succeed or fail together: customer, order, order items, and stock. Without a transaction, a failure partway through could leave stock deducted with no order recorded, or an order with no line items.

**What problem does `lockForUpdate()` solve?**
The classic check-then-act race condition: two concurrent requests could both read "1 unit available" before either writes back, and both proceed to create an order for the same unit. `lockForUpdate()` (a `SELECT ... FOR UPDATE`) makes the second transaction physically block until the first commits, so it's guaranteed to see the post-decrement stock before making its own decision. Full explanation in `docs/concurrency.md`.

**What happens if two orders arrive at nearly the same time for the last unit?**
Exactly one succeeds. The other blocks momentarily on the row lock, then re-checks stock, sees it's now insufficient, and fails cleanly with a `422` — no order or order items created for it, no negative stock. I verified this isn't just logically true but actually true at the database level, by running two real concurrent MySQL connections and confirming the second one's lock acquisition was measurably delayed until the first committed.

**Why use a queue for the confirmation email instead of sending it directly?**
The customer/API caller shouldn't wait on email delivery (real or simulated) as part of the order-creation response, and a slow or failing mail step shouldn't be able to fail an otherwise-valid order. Dispatching a queued job decouples that work from the request/response cycle.

**Why `->afterCommit()` on the job dispatch?**
The dispatch call sits inside the `DB::transaction()` closure. Without `afterCommit()`, a fast queue driver could hand the job to a worker before the transaction commits, and the worker could fail to find the order. `afterCommit()` guarantees the job is only queued once the order is truly durable — see `docs/queue.md`.

**How does customer reuse work?**
`Customer::firstOrCreate(['email' => ...], ['name' => ...])` — email is treated as the unique identity. If it already exists, that row is reused (and the name updated if it changed) instead of creating a duplicate customer.

**How does the low-stock threshold work?**
A single config value, `config('inventory.low_stock_threshold')`, sourced from `LOW_STOCK_THRESHOLD` in `.env` (default 5). `GET /api/products/low-stock` accepts an optional `?threshold=` query parameter to override it per-request. There's exactly one place in the code that reads this value, so there's nothing to keep in sync elsewhere.

**How are validation errors handled?**
Laravel's Form Request validation returns `422` automatically with a structured `errors` object when the request expects JSON. Business-rule failures discovered inside the transaction (insufficient stock, a product that stopped existing) are raised as `ValidationException::withMessages()`, which renders the same way.

**How did you test insufficient stock?**
A feature test creates a product with `stock_on_hand = 1`, requests `quantity = 2`, and asserts a `422`, zero orders created, and stock unchanged (`tests/Feature/OrderCreationTest.php`).

**How would you test concurrency?**
The automated suite has a sequential two-request test proving the check-then-deduct logic is correct, but that runs against SQLite, which doesn't have real multi-connection row locking. To actually verify blocking behavior, I ran two separate PHP processes against a live MySQL database, racing for the same row's lock with an artificial delay — confirming the second process was genuinely blocked and only proceeded after the first committed.

**What would you improve for a production application?**
Authentication/authorization on the API, rate limiting, pagination on order history for customers with very large histories, a real mail driver behind the confirmation job (currently a log entry, as the assignment explicitly allows), and likely moving low-stock/order data to a read-optimized view or cache if the catalog grew large.
