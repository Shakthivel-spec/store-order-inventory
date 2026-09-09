# Screen Recording Script (5–10 minutes)

This is a narration script for the required walkthrough video. It is grounded in what the application actually does — read it alongside the running app rather than reading it verbatim, and adjust pacing to fit the 5–10 minute window.

Before recording: run `php artisan migrate:fresh --seed` so the demo starts from a clean, predictable state, and have `php artisan queue:work` running in a second terminal so the confirmation job visibly processes on camera.

---

### 1. Introduction (30s)
"Hi, I'm walking through the Store Order & Inventory mini-system I built for the Mallow Technologies Laravel Developer take-home assignment. It's a small Laravel app that lets a retail counter record customer orders against a product catalog and keeps stock in sync, with a focus on correct calculations, safe concurrent stock handling, and async processing via a queued job."

### 2. Project overview (30s)
"The core pieces are: Products, Customers, Orders, and Order Items, a `POST /api/orders` endpoint that does validation, tax calculation and stock deduction in one transaction, a customer order history endpoint, a configurable low-stock endpoint, and a queued job that simulates sending a confirmation email."

### 3. Database structure (45s)
Open `docs/database.md` or the migrations in `database/migrations/`.
"Four tables: products, customers, orders, and order_items. Order_items is the interesting one — it's a join table between orders and products, but it also snapshots the product's name, price and tax at the time of purchase, so a price change later doesn't rewrite historical order totals. Money fields are all `DECIMAL`, not float, to avoid rounding drift."

### 4. Application UI (45s)
Open `http://127.0.0.1:8000/`.
"This is the billing screen, based on the wireframe in the assignment. Customer email and name at the top, a product line table in the middle, a low-stock alert panel on the right, and a payment summary at the bottom with subtotal, tax, grand total, amount given, and balance to return."

### 5. Creating an order (90s)
- Type an existing customer's email (e.g. `thomas@example.com`) into the email field, tab out — "notice the name auto-fills because this endpoint looks the customer up by email."
- Add two product rows, pick two different products, change quantities.
- "Watch the line totals, subtotal, tax and grand total update live as I change quantities — this is client-side for responsiveness, but I'll show in a second that the backend never trusts these numbers."
- Click **Generate Bill**.
- "The order was created — id, subtotal, tax and grand total came back from the server."

### 6. Stock deduction (30s)
"If I reload the product list now, the products I just ordered show reduced stock — the deduction happened as part of the same database transaction that created the order, so it's guaranteed to be consistent with the order that was recorded."

### 7. Low-stock alert (30s)
"The panel on the right calls `/api/products/low-stock`, using a configurable threshold from `.env` — `LOW_STOCK_THRESHOLD`. I can also call it with an explicit `?threshold=` query parameter for an ad-hoc view."

### 8. API overview (45s)
Open `docs/api.md` or `routes/api.php`.
"Five endpoints: list products, low-stock products, customer lookup by email, create order, and order history by email. All under `/api`, all returning JSON."

### 9. Order service walkthrough (90s)
Open `app/Services/OrderService.php`.
"This is where all the business logic lives, not in the controller. Inside one `DB::transaction()`: find-or-create the customer, lock and validate stock for every requested product, compute subtotal/tax/grand total from the *locked* product prices — never from anything the client sent — create the order and its line items, deduct stock, and dispatch the confirmation job after the transaction commits."

### 10. Concurrency handling (90s)
Open `docs/concurrency.md`, and/or the `lockForUpdate()` line in `OrderService`.
"This was the requirement I spent the most care on. If two requests come in for the last unit of a product at the same time, without locking they could both read 'one unit available' and both succeed — overselling. `lockForUpdate()` makes the second transaction block until the first one commits, so it always re-reads the *current* stock before deciding. I verified this isn't just logically correct but actually blocks at the database level — I ran two real concurrent MySQL connections racing for the same row lock, and confirmed the second one waited and then correctly failed once it saw the reduced stock. That's documented in `docs/concurrency.md`."

### 11. Queue job (45s)
Show the `queue:work` terminal and `storage/logs/laravel.log`.
"The confirmation job is dispatched with `->afterCommit()`, so it only fires once the order is truly committed — not before. Here's the worker picking it up and logging the simulated confirmation email."

### 12. Tests (45s)
Run `php artisan test` on camera.
"Eleven tests: order creation with correct tax math, insufficient stock rejection, the oversell-protection test, customer reuse, multi-line totals, duplicate product-line merging, order history including the 404 case, and the low-stock endpoint including its default threshold and validation."

### 13. README / documentation (30s)
"The README covers setup, API docs, the database design, architecture, and explicitly documents my assumptions — like treating email as the customer's unique identity, and the payment/balance fields being a UI convenience since they're not part of the required order data model."

### 14. Closing summary (20s)
"That covers the assignment requirements — normalized schema, the order API with validation and safe concurrent stock handling, order history, low stock, a queued confirmation job, and test coverage for the important edge cases. Thanks for watching."
