# Architecture

## Request flow

```text
HTTP Request
    |
    v
Form Request (CreateOrderRequest)   <-- input validation only
    |
    v
Controller (OrderController)        <-- thin: calls the service, formats the response
    |
    v
Service (OrderService)              <-- all business logic lives here
    |
    v
DB::transaction()
    |
    +-- Customer::firstOrCreate()
    +-- Product::lockForUpdate() + stock check
    +-- Order::create()
    +-- OrderItem::create() (one per line)
    +-- Product::decrement('stock_on_hand')
    |
    v
SendOrderConfirmationJob::dispatch()->afterCommit()
    |
    v
JSON response
```

## Layer responsibilities

- **`CreateOrderRequest`** (Form Request) — validates shape and types only: customer name/email present, `products` is a non-empty array, each `product_id` exists in `products`, each `quantity` is a positive integer. It does not know about stock or pricing — that's business logic, not input shape.
- **`OrderController`** — thin. `store()` calls `OrderService::createOrder()` and formats the result as JSON. `history()` looks up the customer and formats their orders. No business rules live in the controller.
- **`OrderService`** — owns the actual business logic: find-or-create the customer, lock and validate stock for every requested product, compute subtotal/tax/grand total, create the order and its line items, deduct stock, dispatch the confirmation job. All of this happens inside one `DB::transaction()`.
- **Models** (`Customer`, `Order`, `OrderItem`, `Product`) — Eloquent relationships and casts only. No business logic in the models.
- **`SendOrderConfirmationJob`** — a queued job. Loads the order by ID and writes a log entry simulating a confirmation email. Runs asynchronously, off the request/response cycle.

## Why a Service instead of a Repository or extra abstraction layer

`OrderService` exists because order creation involves multiple steps that must succeed or fail together (customer lookup, stock locking, order + line creation, stock deduction, job dispatch) — that's real orchestration logic, not something that belongs in a controller action or a model method. It earns its place.

There is deliberately **no repository layer, no interfaces, no service container bindings for swappable implementations**. Eloquent models are used directly. Introducing a repository pattern here would add a layer of indirection with no present benefit — there's one data store, no requirement to swap it out, and Eloquent already provides a clean enough query API. Adding it would be over-engineering for a project this size.

## Why controllers stay thin

`OrderController::store()` is three lines: validate (delegated to the Form Request via type-hinting), call the service, format the response. This makes the controller trivially easy to read and means the business logic (the part that actually needs testing and reasoning about) lives in one place — `OrderService` — instead of being scattered across HTTP-layer code.
