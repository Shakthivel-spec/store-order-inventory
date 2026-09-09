# Store Order & Inventory Mini-System

Laravel take-home assignment for the **Laravel Developer** position at Mallow Technologies.

The application provides a small retail counter workflow for recording customer orders against a product catalog while keeping stock synchronized.

## Requirements covered

- Normalized database schema for products, customers, orders and order items.
- Customer order creation API with validation.
- Automatic subtotal, tax and grand-total calculation.
- Stock validation and stock deduction.
- Customer order history by email.
- Configurable low-stock API.
- Database-backed queued order-confirmation job simulation.
- Feature tests (11) covering successful orders, insufficient stock, oversell protection, customer reuse, multi-line totals, duplicate-line merging, order history (including 404) and low stock.
- Concurrency-safe stock check/deduction using a database transaction and `lockForUpdate()`.
- Small browser UI based on the supplied wireframe for an easier walkthrough.

## Tech stack

- PHP 8.2+
- Laravel 12
- MySQL 8+ (recommended for local development)
- PHPUnit 11
- Eloquent ORM
- Database queue
- Blade + vanilla JavaScript for the demo UI

## Local setup

### 1. Install dependencies

After extracting the project:

```bash
composer install
```

### 2. Create the environment file

```bash
cp .env.example .env
```

On Windows PowerShell, you can use:

```powershell
Copy-Item .env.example .env
```

Generate the application key:

```bash
php artisan key:generate
```

### 3. Create the MySQL database

Create a database named:

```text
store_order_inventory
```

Then check the database values in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=store_order_inventory
DB_USERNAME=root
DB_PASSWORD=
```

Adjust username/password if your local MySQL installation uses different credentials.

### 4. Run migrations and seed demo data

```bash
php artisan migrate --seed
```

The seeder creates products such as Colgate Toothpaste, Parle-G Biscuit, Milk 1L and Eggs (12), plus sample customers.

### 5. Start Laravel

```bash
php artisan serve
```

Open:

```text
http://127.0.0.1:8000
```

### 6. Start the queue worker

The application uses the database queue driver so the confirmation job is actually queued instead of being executed directly during the HTTP request.

Open a second terminal:

```bash
php artisan queue:work
```

After an order is created, the job writes a simulated confirmation-email entry to `storage/logs/laravel.log`.

## API endpoints

### Get products

```http
GET /api/products
```

### Get low-stock products

Uses `LOW_STOCK_THRESHOLD` from `.env` by default. A request-level threshold can also be supplied.

```http
GET /api/products/low-stock
GET /api/products/low-stock?threshold=3
```

### Find an existing customer by email

```http
GET /api/customers/lookup?email=thomas@example.com
```

### Create an order

```http
POST /api/orders
Content-Type: application/json
```

Example request:

```json
{
  "customer": {
    "name": "Thomas Anderson",
    "email": "thomas@example.com"
  },
  "products": [
    {
      "product_id": 1,
      "quantity": 2
    },
    {
      "product_id": 2,
      "quantity": 5
    }
  ]
}
```

The response contains the created order, customer, item-level totals, subtotal, tax and grand total.

### Customer order history

```http
GET /api/orders/history?email=thomas@example.com
```

Returns `404` if no customer exists for that email. Returns `200` with an empty `data` array if the customer exists but has no orders yet.

## Database relationships

```text
Customer 1 ───< Order 1 ───< OrderItem >─── 1 Product
```

- `Customer::orders()` — hasMany `Order`
- `Order::customer()` — belongsTo `Customer`
- `Order::items()` — hasMany `OrderItem`
- `OrderItem::order()` — belongsTo `Order`
- `OrderItem::product()` — belongsTo `Product`
- `Product::orderItems()` — hasMany `OrderItem`

`order_items` also stores a snapshot of the product's name, code, unit price and tax percentage at the time of purchase (see "Order totals" below), so it is a join table with its own historical columns rather than a plain pivot.

## Stock concurrency design

The stock check and deduction are intentionally handled inside one database transaction.

For each requested product, the service obtains a row lock using:

```php
Product::query()
    ->whereKey($productId)
    ->lockForUpdate()
    ->first();
```

The service then checks the locked `stock_on_hand` value and only deducts stock after the check passes.

This means two concurrent transactions cannot both read the same last unit and both deduct it. The first transaction obtains the product row lock and succeeds; the second waits for the lock, sees the updated stock, and fails with a validation response.

Requested product IDs are sorted before acquiring locks. This keeps lock acquisition order consistent and reduces the chance of deadlocks when multiple products are ordered concurrently.

**Why locking is required here specifically:** without `lockForUpdate()`, two concurrent requests could both `SELECT` the same product row, both see `stock_on_hand = 1`, both conclude the order is valid, and both `UPDATE` the stock — resulting in `stock_on_hand = -1` and two orders created for one physical unit. `lockForUpdate()` forces the second transaction's `SELECT ... FOR UPDATE` to block until the first transaction commits or rolls back, so the second request always re-reads the *post-decrement* value before deciding whether stock is sufficient.

**How this was verified:** the automated test (`test_a_second_order_cannot_oversell_the_last_unit`) issues two sequential HTTP requests against SQLite, which proves the validation/locking *logic* is correct but SQLite does not have real multi-connection row locking, so it cannot prove blocking behaviour under genuine concurrent load. To verify the actual database-level lock, this was also tested manually against MySQL with two real, concurrently-running PHP processes racing for `lockForUpdate()` on the same row (one process holding the lock for 1.5s while the second waited): the second process's lock acquisition was measurably blocked until the first committed, then correctly observed the reduced stock and failed. This manual scenario is not part of the automated suite because PHPUnit runs single-threaded against one connection; it was a one-off verification during development.

## Order totals

Each order item stores a snapshot of the product name, code, unit price and tax percentage. This preserves the historical values even if the product catalog is changed later.

For every line:

```text
line subtotal = unit price × quantity
line tax      = line subtotal × tax percentage / 100
after-tax line total = line subtotal + line tax
```

Order totals are the sum of the line values.

## Queue design

`SendOrderConfirmationJob` implements `ShouldQueue` and is dispatched with `->afterCommit()` from inside `OrderService::createOrder()`'s transaction.

`afterCommit()` matters here: the dispatch call itself executes *inside* the `DB::transaction()` closure, before the order is guaranteed durable. Without `afterCommit()`, a fast queue worker on a driver like Redis or SQS could start processing the job before the transaction commits, and fail to find the order. `afterCommit()` defers the actual dispatch until the transaction succeeds, so the job is only ever queued for an order that is genuinely committed to the database. (With the `database` queue driver used here, the job row insert would in practice also be rolled back along with everything else if the transaction failed, but `afterCommit()` makes the guarantee explicit and driver-independent.)

No real SMTP integration is required for the assignment. The job loads the order and writes a structured log entry simulating the confirmation email.

## Tests

Run all tests with:

```bash
php artisan test
```

The feature tests cover:

1. Successful order creation, tax calculation and stock reduction.
2. Insufficient stock — order is rejected (422) and stock remains unchanged.
3. Sequential oversell attempt on the last unit of stock (validates the locking logic; see the concurrency note above for how real concurrent blocking was verified against MySQL).
4. Customer order history by email, including a 404 for an unknown email.
5. Existing customer reuse — ordering again with the same email updates the existing customer instead of creating a duplicate.
6. Multi-line orders — subtotal/tax/grand total sum correctly across several product lines.
7. Duplicate product lines in one request are merged into a single order item with a combined quantity.
8. Low-stock endpoint with an explicit threshold, the configured default threshold, and rejection of a negative threshold.
9. Queued job dispatch is asserted with `Queue::fake()`.

## Design decisions / assumptions

1. **Customer identity:** email is the unique customer identifier. If an email already exists, the existing customer is reused and the submitted name is updated.
2. **Order item history:** product details used at purchase time are copied to `order_items` so historical orders are not changed by later catalog edits.
3. **Tax:** tax is calculated per order line and rounded to two decimal places before the order-level totals are stored.
4. **Low stock:** the default threshold comes from `LOW_STOCK_THRESHOLD`; an API caller may provide a non-negative `threshold` query parameter for a different view. There is exactly one place in the code that reads this config value (`ProductController::lowStock`), so there is nothing else to keep in sync if the default changes.
5. **Payment amount:** the supplied wireframe shows an amount-given field and balance-to-return. This is implemented as a client-side billing convenience because payment amount is not part of the required order API/data model.
6. **Email:** the confirmation is simulated with a log entry; no real SMTP credentials are required.
7. **Authentication:** not required by the assignment, so the demo intentionally has no login layer.
8. **Duplicate product lines:** if a request lists the same `product_id` more than once (e.g. `[{product_id: 1, qty: 1}, {product_id: 1, qty: 2}]`), the quantities are merged into a single order line rather than rejected, since a billing UI could plausibly add the same product twice by mistake and merging is more forgiving than failing the whole order.
9. **Order history 404 vs empty list:** `GET /api/orders/history` returns `404` only when the email does not match any customer at all. A customer that exists but has no orders yet returns `200` with an empty `data` array, since "no orders" is a valid, non-error state for an existing customer.

## Known limitations

- The automated concurrency test (`test_a_second_order_cannot_oversell_the_last_unit`) runs against SQLite with two sequential requests in the same process. This is sufficient to prove the check-then-deduct logic is correct, but SQLite doesn't have MySQL-style multi-connection row locking, so the test cannot by itself prove that concurrent requests genuinely block on the database. That was verified manually against MySQL with two real concurrently-running processes (see "Stock concurrency design" above); it isn't automated because PHPUnit's HTTP test client isn't well suited to driving genuinely parallel requests against a running server.
- There is no authentication/authorization layer — out of scope for this assignment.
- No API rate limiting or pagination on the order-history endpoint — acceptable for a mini-system with a small seeded catalog, but would need addressing before handling a customer with a very large order history.

## Project structure

```text
app/
├── Http/
│   ├── Controllers/
│   └── Requests/
├── Jobs/
├── Models/
└── Services/

database/
├── factories/
├── migrations/
└── seeders/

resources/views/
routes/
tests/Feature/

docs/
├── architecture.md
├── database.md
├── api.md
├── order-flow.md
├── concurrency.md
├── queue.md
├── interview-preparation.md
├── screen-recording-script.md
└── final-requirement-checklist.md

prompts/
└── README.md
```

Controllers are intentionally thin. Validation lives in a Form Request, business logic lives in `OrderService`, and asynchronous confirmation work lives in a queued Job.

## Further documentation

This README covers setup, the API, and the key design decisions. The `docs/` folder goes deeper on specific topics for anyone reviewing this as a take-home submission:

- [`docs/architecture.md`](docs/architecture.md) — request flow and why logic is placed where it is
- [`docs/database.md`](docs/database.md) — schema, relationships, why `order_items` snapshots product data
- [`docs/api.md`](docs/api.md) — full endpoint reference with real request/response examples
- [`docs/order-flow.md`](docs/order-flow.md) — step-by-step order creation, including failure handling
- [`docs/concurrency.md`](docs/concurrency.md) — the race condition, why `lockForUpdate()` solves it, and how it was verified against real MySQL
- [`docs/queue.md`](docs/queue.md) — the confirmation job and why it's dispatched `afterCommit()`
- [`docs/interview-preparation.md`](docs/interview-preparation.md) — likely questions and answers grounded in this code
- [`docs/screen-recording-script.md`](docs/screen-recording-script.md) — narration script for the required walkthrough video
- [`docs/final-requirement-checklist.md`](docs/final-requirement-checklist.md) — requirement-by-requirement status with evidence

## Prompt log

AI-assisted development (Claude) is allowed by the assignment and was used for this project, including an end-to-end review against the assignment brief. `prompts/README.md` explains what to add: real screenshots of the actual prompts used, taken from the chat panel. No placeholder or fabricated screenshots have been added — that step is a manual one left for submission.

## Suggested walkthrough

A full narration script is in [`docs/screen-recording-script.md`](docs/screen-recording-script.md). Short version, for the narrated 5–10 minute walkthrough:

1. Show the database migrations and relationships.
2. Show the order form and seeded products.
3. Create a normal order and show stock reduction.
4. Show the queued job in the worker/log.
5. Trigger an insufficient-stock order and explain the validation response.
6. Show the order-history and low-stock endpoints.
7. Explain `OrderService`, transaction boundaries and `lockForUpdate()`, and how the concurrency guarantee was verified against real MySQL.
8. Run the feature tests.
