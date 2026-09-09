# API Documentation

All endpoints are prefixed with `/api`. Send `Accept: application/json` on every request so Laravel returns JSON error bodies (without it, validation/not-found errors render as an HTML redirect instead of JSON — this is default Laravel behavior, not a bug in this app).

> Note: an earlier draft of this document assumed a `GET /api/customers/{email}/orders` route. The actual implemented route is `GET /api/orders/history?email=...` (see `routes/api.php`) — documented below to match the real code.

---

## `GET /api/products`

List all products.

**Response `200`**
```json
{
  "data": [
    { "id": 1, "name": "Colgate Toothpaste", "code": "SKU-COLGATE", "price": "54.00", "tax_percentage": "18.00", "stock_on_hand": 12 }
  ]
}
```

---

## `GET /api/products/low-stock`

Products at or below a stock threshold.

**Query parameters**

| Param | Required | Rule | Default |
|---|---|---|---|
| `threshold` | no | integer, min 0 | `config('inventory.low_stock_threshold')` (from `LOW_STOCK_THRESHOLD` in `.env`, default `5`) |

**Response `200`**
```json
{
  "threshold": 5,
  "data": [
    { "id": 4, "name": "Eggs (12)", "code": "SKU-EGGS12", "price": "90.00", "tax_percentage": "5.00", "stock_on_hand": 2 }
  ]
}
```

**Response `422`** — negative threshold, e.g. `?threshold=-1`
```json
{ "message": "The threshold field must be at least 0.", "errors": { "threshold": ["..."] } }
```

---

## `GET /api/customers/lookup`

Look up an existing customer by email (used by the billing UI to auto-fill the name field).

**Query parameters**

| Param | Required | Rule |
|---|---|---|
| `email` | yes | valid email |

**Response `200`** (found)
```json
{ "data": { "id": 1, "name": "Thomas Anderson", "email": "thomas@example.com" } }
```

**Response `200`** (not found — `data` is `null`, not a 404, since this endpoint's contract is "look up if present")
```json
{ "data": null }
```

---

## `POST /api/orders`

Create an order. Validates stock, computes totals, deducts stock, dispatches the confirmation job.

**Request body**
```json
{
  "customer": { "name": "Thomas Anderson", "email": "thomas@example.com" },
  "products": [
    { "product_id": 1, "quantity": 2 },
    { "product_id": 2, "quantity": 5 }
  ]
}
```

**Validation rules** (`app/Http/Requests/CreateOrderRequest.php`)

| Field | Rule |
|---|---|
| `customer.name` | required, string, max 255 |
| `customer.email` | required, valid email, max 255 |
| `products` | required, array, min 1 |
| `products.*.product_id` | required, integer, must exist in `products` table |
| `products.*.quantity` | required, integer, min 1 |

If the same `product_id` appears more than once in `products`, the quantities are merged into one order line rather than rejected.

**Response `201`**
```json
{
  "message": "Order created successfully.",
  "data": {
    "id": 1,
    "customer": { "name": "Thomas Anderson", "email": "thomas@example.com" },
    "subtotal": 158,
    "tax_amount": 21.94,
    "grand_total": 179.94,
    "created_at": "2026-09-09T08:15:16.000000Z",
    "items": [
      {
        "product_id": 1, "product_name": "Colgate Toothpaste", "product_code": "SKU-COLGATE",
        "unit_price": 54, "tax_percentage": 18, "quantity": 2,
        "line_subtotal": 108, "line_tax": 19.44, "line_total": 127.44
      }
    ]
  }
}
```

**Response `422`** — validation failure (bad shape, unknown product, missing fields)
```json
{ "message": "The products field is required.", "errors": { "products": ["The products field is required."] } }
```

**Response `422`** — insufficient stock (raised from `OrderService`, not the Form Request, since stock availability isn't known until the row is locked)
```json
{
  "message": "Insufficient stock for Eggs (12). Available: 0, requested: 1.",
  "errors": { "products": ["Insufficient stock for Eggs (12). Available: 0, requested: 1."] }
}
```

Only client-supplied fields are `product_id` and `quantity` — price and tax are always read from the database inside the transaction, never trusted from the request body.

---

## `GET /api/orders/history`

A customer's order history by email, including line items.

**Query parameters**

| Param | Required | Rule |
|---|---|---|
| `email` | yes | valid email |

**Response `200`**
```json
{
  "data": [
    {
      "id": 2, "customer": { "name": "Thomas Anderson", "email": "thomas@example.com" },
      "subtotal": 62, "tax_amount": 3.1, "grand_total": 65.1,
      "created_at": "2026-09-09T08:15:16.000000Z",
      "items": [ { "product_id": 3, "product_name": "Milk 1L", "...": "..." } ]
    }
  ]
}
```
Ordered newest first (`latest()`).

**Response `404`** — no customer exists for that email at all
```json
{ "message": "No customer found for this email." }
```

An existing customer with zero orders returns `200` with `"data": []` — that is a valid state, not an error.
