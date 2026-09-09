<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Billing — New Order</title>
    <style>
        :root { --navy:#1f2a3d; --border:#b7c2d0; --muted:#64748b; --panel:#eef3f8; --green:#168347; --warning:#fff9e7; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:Arial, Helvetica, sans-serif; background:#f7f9fb; color:#172033; }
        .container { max-width:1120px; margin:32px auto; padding:0 20px 50px; }
        .header { background:var(--navy); color:white; padding:18px 22px; border-radius:10px 10px 0 0; display:flex; justify-content:space-between; align-items:center; }
        .header h1 { margin:0; font-size:20px; }
        .header span { font-size:12px; opacity:.75; }
        .layout { display:grid; grid-template-columns:1fr 300px; gap:20px; margin-top:20px; }
        .card { background:white; border:1px solid #dbe2ea; border-radius:10px; padding:20px; box-shadow:0 2px 10px rgba(15,23,42,.04); }
        .customer-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        label { display:block; font-weight:700; font-size:13px; margin-bottom:6px; }
        input, select { width:100%; padding:10px 11px; border:1px solid var(--border); border-radius:6px; font-size:14px; background:white; }
        input:focus, select:focus { outline:2px solid #c7d2fe; border-color:#7c8cff; }
        .section-title { font-size:13px; font-weight:800; margin:22px 0 10px; text-transform:uppercase; letter-spacing:.03em; }
        table { width:100%; border-collapse:collapse; }
        th, td { border:1px solid var(--border); padding:10px; font-size:13px; text-align:left; }
        th { background:#e8eef5; }
        td:nth-child(2), td:nth-child(3), td:nth-child(4) { text-align:right; }
        .qty { max-width:80px; text-align:right; }
        .row-actions { width:40px; text-align:center !important; }
        button { border:0; border-radius:6px; padding:10px 14px; font-weight:700; cursor:pointer; }
        .add { margin-top:10px; background:#eef2f7; color:#26344a; }
        .remove { background:#fee2e2; color:#991b1b; padding:5px 8px; }
        .summary { margin-top:18px; background:var(--panel); padding:16px; border-radius:8px; max-width:440px; }
        .summary-row { display:flex; justify-content:space-between; padding:5px 0; }
        .summary-total { border-top:1px dashed #9aa8b9; margin-top:7px; padding-top:9px; font-size:16px; font-weight:800; }
        .action-row { display:flex; align-items:flex-end; gap:20px; margin-top:18px; }
        .primary { background:var(--green); color:white; min-width:150px; }
        .primary:disabled { opacity:.55; cursor:not-allowed; }
        .hint { color:var(--muted); font-size:12px; margin-top:5px; }
        .alert { background:var(--warning); border:1px solid #e9c46a; border-radius:8px; padding:16px; }
        .alert h3 { margin:0 0 12px; font-size:14px; }
        .alert ul { margin:0; padding-left:18px; font-size:13px; line-height:1.8; }
        .status { margin-top:14px; padding:10px 12px; border-radius:6px; display:none; font-size:13px; }
        .status.success { display:block; background:#dcfce7; color:#166534; }
        .status.error { display:block; background:#fee2e2; color:#991b1b; }
        .history { margin-top:20px; }
        .history pre { background:#0f172a; color:#e2e8f0; padding:15px; border-radius:8px; overflow:auto; font-size:12px; }
        @media (max-width:800px) { .layout { grid-template-columns:1fr; } .customer-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Store Billing — New Order</h1>
        <span>Laravel Store Order &amp; Inventory</span>
    </div>

    <div class="layout">
        <main class="card">
            <div class="customer-grid">
                <div>
                    <label for="customerEmail">Customer Email</label>
                    <input id="customerEmail" type="email" placeholder="e.g. thomas@example.com" autocomplete="email">
                    <div class="hint" id="customerHint">Existing customers are recognised by email.</div>
                </div>
                <div>
                    <label for="customerName">Name</label>
                    <input id="customerName" type="text" placeholder="Customer name" autocomplete="name">
                </div>
            </div>

            <div class="section-title">Products</div>
            <table>
                <thead>
                <tr>
                    <th>Product</th><th>Qty</th><th>Price</th><th>Line Total</th><th class="row-actions"></th>
                </tr>
                </thead>
                <tbody id="productRows"></tbody>
            </table>
            <button class="add" type="button" id="addProduct">+ Add Product</button>

            <div class="section-title">Payment</div>
            <div class="summary">
                <div class="summary-row"><span>Subtotal</span><strong id="subtotal">₹0.00</strong></div>
                <div class="summary-row"><span>Tax</span><strong id="tax">₹0.00</strong></div>
                <div class="summary-row summary-total"><span>Grand Total</span><strong id="grandTotal">₹0.00</strong></div>
                <div style="margin-top:12px">
                    <label for="amountPaid">Amount Given by Customer</label>
                    <input id="amountPaid" type="number" min="0" step="0.01" placeholder="₹0.00">
                </div>
                <div class="summary-row" style="margin-top:8px"><span>Balance to Return</span><strong id="balance">₹0.00</strong></div>
            </div>

            <div class="action-row">
                <button class="primary" type="button" id="generateBill">Generate Bill</button>
                <span class="hint">Creates the order and queues the confirmation email simulation.</span>
            </div>
            <div id="status" class="status"></div>
        </main>

        <aside class="alert">
            <h3>⚠ Low Stock Alert</h3>
            <ul id="lowStockList"><li>Loading...</li></ul>
        </aside>
    </div>

    <section class="card history">
        <div class="section-title" style="margin-top:0">Customer Order History</div>
        <button class="add" type="button" id="loadHistory">Load History</button>
        <pre id="historyOutput">Enter a customer email and click Load History.</pre>
    </section>
</div>

<script>
const state = { products: [] };
const money = value => `₹${Number(value || 0).toFixed(2)}`;

async function api(url, options = {}) {
    const response = await fetch(url, { headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, ...options });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(' ') || 'Request failed.');
    return body;
}

function productOptions(selected = '') {
    return '<option value="">Select product</option>' + state.products.map(product =>
        `<option value="${product.id}" ${String(product.id) === String(selected) ? 'selected' : ''}>${product.name} (${product.stock_on_hand} left)</option>`
    ).join('');
}

function addRow() {
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><select class="product">${productOptions()}</select></td>
        <td><input class="qty" type="number" min="1" value="1"></td>
        <td class="price">₹0.00</td>
        <td class="lineTotal">₹0.00</td>
        <td class="row-actions"><button type="button" class="remove">×</button></td>`;
    document.getElementById('productRows').appendChild(row);
    row.querySelector('.product').addEventListener('change', recalculate);
    row.querySelector('.qty').addEventListener('input', recalculate);
    row.querySelector('.remove').addEventListener('click', () => { row.remove(); recalculate(); });
    recalculate();
}

function recalculate() {
    let subtotal = 0, tax = 0;
    document.querySelectorAll('#productRows tr').forEach(row => {
        const product = state.products.find(item => String(item.id) === row.querySelector('.product').value);
        const quantity = Math.max(0, Number(row.querySelector('.qty').value || 0));
        if (!product) { row.querySelector('.price').textContent = money(0); row.querySelector('.lineTotal').textContent = money(0); return; }
        const lineSubtotal = Number(product.price) * quantity;
        const lineTax = lineSubtotal * Number(product.tax_percentage) / 100;
        subtotal += lineSubtotal; tax += lineTax;
        row.querySelector('.price').textContent = money(product.price);
        row.querySelector('.lineTotal').textContent = money(lineSubtotal + lineTax);
    });
    const total = subtotal + tax;
    document.getElementById('subtotal').textContent = money(subtotal);
    document.getElementById('tax').textContent = money(tax);
    document.getElementById('grandTotal').textContent = money(total);
    const paid = Number(document.getElementById('amountPaid').value || 0);
    document.getElementById('balance').textContent = money(Math.max(0, paid - total));
}

async function loadProducts() {
    const response = await api('/api/products');
    state.products = response.data;
    if (!document.querySelector('#productRows tr')) addRow();
}

async function loadLowStock() {
    const response = await api('/api/products/low-stock');
    const list = document.getElementById('lowStockList');
    list.innerHTML = response.data.length
        ? response.data.map(product => `<li><strong>${product.name}</strong> — ${product.stock_on_hand} units left</li>`).join('')
        : '<li>No products below the configured threshold.</li>';
}

document.getElementById('addProduct').addEventListener('click', addRow);
document.getElementById('amountPaid').addEventListener('input', recalculate);

document.getElementById('customerEmail').addEventListener('blur', async event => {
    const email = event.target.value.trim();
    if (!email) return;
    try {
        const response = await api(`/api/customers/lookup?email=${encodeURIComponent(email)}`);
        if (response.data) {
            document.getElementById('customerName').value = response.data.name;
            document.getElementById('customerHint').textContent = 'Existing customer found.';
        } else {
            document.getElementById('customerHint').textContent = 'New customer — they will be created with this email.';
        }
    } catch (_) {}
});

document.getElementById('generateBill').addEventListener('click', async () => {
    const status = document.getElementById('status');
    status.className = 'status';
    const rows = [...document.querySelectorAll('#productRows tr')];
    const products = rows.map(row => ({ product_id: Number(row.querySelector('.product').value), quantity: Number(row.querySelector('.qty').value) }))
        .filter(item => item.product_id && item.quantity > 0);
    const customer = { email: document.getElementById('customerEmail').value.trim(), name: document.getElementById('customerName').value.trim() };
    if (!customer.email || !customer.name || !products.length) {
        status.textContent = 'Customer details and at least one product are required.';
        status.className = 'status error';
        return;
    }
    try {
        const response = await api('/api/orders', { method: 'POST', body: JSON.stringify({ customer, products }) });
        status.textContent = `Order #${response.data.id} created successfully. Total: ${money(response.data.grand_total)}. Confirmation job queued.`;
        status.className = 'status success';
        document.getElementById('productRows').innerHTML = '';
        await loadProducts();
        await loadLowStock();
    } catch (error) {
        status.textContent = error.message;
        status.className = 'status error';
    }
});

document.getElementById('loadHistory').addEventListener('click', async () => {
    const email = document.getElementById('customerEmail').value.trim();
    const output = document.getElementById('historyOutput');
    if (!email) { output.textContent = 'Enter a customer email first.'; return; }
    try {
        const response = await api(`/api/orders/history?email=${encodeURIComponent(email)}`);
        output.textContent = JSON.stringify(response.data, null, 2);
    } catch (error) { output.textContent = error.message; }
});

Promise.all([loadProducts(), loadLowStock()]).catch(error => {
    document.getElementById('status').textContent = error.message;
    document.getElementById('status').className = 'status error';
});
</script>
</body>
</html>
