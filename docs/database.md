# Database Design

## Tables

### `products`

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| name | string | |
| code | string | unique — this is the product's SKU |
| price | decimal(12,2) | price per unit |
| tax_percentage | decimal(5,2) | default 0 |
| stock_on_hand | unsigned int | default 0, never allowed to go negative |
| timestamps | | |

### `customers`

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| name | string | |
| email | string | unique — this is how a customer is identified across orders |
| timestamps | | |

### `orders`

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| customer_id | bigint, FK → customers.id, cascade on delete | |
| subtotal | decimal(12,2) | sum of line subtotals |
| tax_amount | decimal(12,2) | sum of line tax |
| grand_total | decimal(12,2) | subtotal + tax_amount |
| timestamps | | |

### `order_items`

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| order_id | bigint, FK → orders.id, cascade on delete | |
| product_id | bigint, FK → products.id, restrict on delete | a product can't be deleted while it's referenced by an order line |
| product_name | string | **snapshot**, copied from the product at order time |
| product_code | string | **snapshot** |
| unit_price | decimal(12,2) | **snapshot** |
| tax_percentage | decimal(5,2) | **snapshot** |
| quantity | unsigned int | |
| line_subtotal | decimal(12,2) | unit_price × quantity |
| line_tax | decimal(12,2) | line_subtotal × tax_percentage / 100 |
| line_total | decimal(12,2) | line_subtotal + line_tax |
| timestamps | | |
| index | (order_id, product_id) | speeds up per-order, per-product lookups |

All money fields use `decimal`, never `float`, to avoid floating-point rounding errors in totals.

## Relationship diagram

```text
Customer
   |
   | hasMany
   v
Order
   |
   | hasMany
   v
OrderItem
   |
   | belongsTo
   v
Product
```

Eloquent relationships (see `app/Models/`):

- `Customer::orders()` — hasMany `Order`
- `Order::customer()` — belongsTo `Customer`
- `Order::items()` — hasMany `OrderItem`
- `OrderItem::order()` — belongsTo `Order`
- `OrderItem::product()` — belongsTo `Product`
- `Product::orderItems()` — hasMany `OrderItem`

## Why `order_items` exists instead of storing products directly on `orders`

An order can contain **more than one product** (the assignment requires "one or more product lines"), so a single `orders` row cannot hold a single `product_id` — a join table is required to express a many-to-many relationship between orders and products, with per-line data (quantity, line total) attached to the join itself. That's exactly what `order_items` is.

The second reason `order_items` carries its own `product_name`, `product_code`, `unit_price` and `tax_percentage` columns — instead of only storing `product_id` and joining to `products` for that data — is **historical accuracy**. If a product's price or tax rate changes after an order was placed, that shouldn't silently rewrite the total on a past invoice. Snapshotting the values used at the moment of purchase means an order's total is always reproducible from its own rows, regardless of what happens to the product catalog afterwards.

## Why decimals, not floats

`price`, `tax_percentage`, `subtotal`, `tax_amount`, `grand_total`, and every line-level money column use MySQL `DECIMAL`, cast to `decimal:2` on the Eloquent models. Floats are binary approximations and can introduce cent-level rounding errors when summed across many order lines; `DECIMAL` stores an exact value, which matters for anything involving money.
