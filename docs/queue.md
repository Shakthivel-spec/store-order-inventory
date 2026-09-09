# Queue / Confirmation Job

## Flow

```text
Order created (transaction commits)
        |
        v
SendOrderConfirmationJob::dispatch($order->id)->afterCommit()
        |
        v
jobs table (database queue driver)
        |
        v
php artisan queue:work   (a separate worker process)
        |
        v
SendOrderConfirmationJob::handle()
        |
        v
Log::info('Order confirmation email simulated.', [...])
        -> storage/logs/laravel.log
```

## Why a queued job instead of sending the email directly

Sending an email (real or simulated) is not something the customer needs to wait for as part of the HTTP response — the order is already valid and committed by the time the confirmation step runs. Doing it synchronously would make every order request slower for no benefit to the caller, and a slow/failing mail step could needlessly fail an otherwise-successful order. Dispatching it as a queued job means the HTTP response returns as soon as the order is created, and the confirmation work happens asynchronously, retried independently if it fails (`public int $tries = 3;` on the job).

## Why `->afterCommit()` matters

`SendOrderConfirmationJob::dispatch($order->id)` is called from *inside* `OrderService::createOrder()`'s `DB::transaction()` closure — see `docs/order-flow.md` step 11. Without `->afterCommit()`, the dispatch call would attempt to hand the job off to the queue backend immediately, before the transaction has actually committed. On a fast, non-database queue driver (Redis, SQS), a worker could pick the job up and try to load the order by ID before it's actually visible in the database — the job would fail simply due to timing. `->afterCommit()` defers the dispatch until the surrounding transaction has committed successfully, guaranteeing the job is only ever queued for an order that genuinely exists.

(With the `database` queue driver configured for local development, the job row's own insert would in practice also roll back along with the rest of the transaction if something failed — so the failure mode `afterCommit()` guards against is more about a *future* driver switch than today's default config. Being explicit here is cheap and removes a subtle, easy-to-miss coupling to the current queue driver.)

## Running it locally

```bash
php artisan queue:work
```

This is a separate long-running process from `php artisan serve` — start it in a second terminal. Every order created while a request is being handled will have its confirmation job picked up by this worker and logged to `storage/logs/laravel.log` as:

```
[timestamp] local.INFO: Order confirmation email simulated. {"order_id":1,"customer_email":"thomas@example.com","grand_total":"179.94"}
```

No real SMTP configuration is required — the job never touches Laravel's mailer, it only writes a structured log line, which satisfies the assignment's "log entry or fake mailer is fine" allowance.

## Testing

`Queue::fake()` is used in the order-creation feature test to assert `SendOrderConfirmationJob` was pushed, without actually running a worker or writing to the log during the test run (`Queue::assertPushed(SendOrderConfirmationJob::class)`).
