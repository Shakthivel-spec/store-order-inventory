# Final Requirement Checklist

Cross-checked against the Mallow Technologies Laravel Developer Mini Task PDF. Evidence points at real files/commands, not assumptions.

| Requirement | Status | Evidence/File | Notes |
|---|---|---|---|
| Products (name, code, price, tax, stock) | PASS | `database/migrations/2026_09_09_000001_create_products_table.php`, `app/Models/Product.php` | unique `code`, decimal price/tax, unsigned int stock |
| Customers (name, unique email) | PASS | `database/migrations/2026_09_09_000002_create_customers_table.php`, `app/Models/Customer.php` | email unique constraint |
| Orders (customer, lines, subtotal, tax, grand total) | PASS | `database/migrations/2026_09_09_000003_create_orders_table.php` | decimal totals |
| Normalized schema, FKs, unique constraints, indexes | PASS | 4 migrations in `database/migrations/` | `order_items` indexed on `(order_id, product_id)`; cascade/restrict delete rules set appropriately |
| Eloquent relationships (all 6 required) | PASS | `app/Models/*.php` | Customer↔Order, Order↔OrderItem, OrderItem↔Product all present both directions |
| Factories & seeders | PASS | `database/factories/`, `database/seeders/DatabaseSeeder.php` | verified with `php artisan migrate:fresh --seed` against live MySQL |
| Create Order API | PASS | `POST /api/orders`, `app/Http/Controllers/OrderController.php`, `app/Services/OrderService.php` | live-verified: creates order, deducts stock, ignores client-supplied price |
| Customer Order History API | PASS | `GET /api/orders/history`, `app/Http/Controllers/OrderController.php` | now returns 404 for unknown email, 200+empty array for known customer with no orders |
| Low Stock API, configurable threshold | PASS | `GET /api/products/low-stock`, `config/inventory.php` | single source of threshold truth, default + override + validation |
| Form Request validation | PASS | `app/Http/Requests/CreateOrderRequest.php` | |
| Queued confirmation job | PASS | `app/Jobs/SendOrderConfirmationJob.php` | dispatched with `->afterCommit()`; verified end-to-end with real `queue:work` + log output |
| Concurrency-safe stock check/deduct | PASS | `app/Services/OrderService.php` (`lockForUpdate()` inside `DB::transaction()`) | verified with two real concurrent MySQL connections, not just the sequential PHPUnit test — see `docs/concurrency.md` |
| Feature/unit tests | PASS | `tests/Feature/OrderCreationTest.php`, `tests/Feature/LowStockTest.php` | 11 tests, 43 assertions, all passing (`php artisan test`) |
| Insufficient stock handling | PASS | test: `test_it_rejects_an_order_when_stock_is_insufficient_and_does_not_create_order` | 422, no order created, stock unchanged |
| Overselling protection | PASS | test: `test_a_second_order_cannot_oversell_the_last_unit` + manual MySQL verification | |
| Billing UI matching wireframe | PASS | `resources/views/store.blade.php` | customer fields, product rows, low-stock panel, payment summary, generate bill — all present; backend computes final totals |
| README | PASS | `README.md` | setup, API docs, architecture, concurrency, assumptions, limitations |
| Architecture documentation | PASS | `docs/architecture.md` | |
| Database documentation | PASS | `docs/database.md` | |
| API documentation | PASS | `docs/api.md` | matches actual registered routes (`php artisan route:list`), not assumed ones |
| Order flow documentation | PASS | `docs/order-flow.md` | |
| Concurrency documentation | PASS | `docs/concurrency.md` | |
| Queue documentation | PASS | `docs/queue.md` | |
| Interview preparation notes | PASS | `docs/interview-preparation.md` | |
| Prompt Log structure | PASS | `prompts/README.md` | structure and instructions ready; actual screenshots are a manual step |
| Prompt Log screenshots | MANUAL | — | must be added by the candidate; not fabricated |
| Screen recording | MANUAL | `docs/screen-recording-script.md` (script prepared) | actual narrated recording must be done by the candidate |
| Authentication/login | NOT IMPLEMENTED (by decision) | — | assignment does not require it; skipped to avoid unnecessary scope beyond the brief |
