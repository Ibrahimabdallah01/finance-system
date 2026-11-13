import { useState } from 'react';
import client from '../api/client';
import DataTable from '../components/DataTable';
import FormModal from '../components/FormModal';
import PurchaseForm from '../components/PurchaseForm';
import { Badge, Button, ErrorBanner, PageHeader, StatCard, Tabs } from '../components/ui';
import { useToast } from '../context/ToastContext';
import { announceDataChanged, useDataChanged } from '../hooks/useDataChanged';
import { useDebounce } from '../hooks/useDebounce';
import { useFetch } from '../hooks/useFetch';
import { usePagedList } from '../hooks/usePagedList';
import { formatDate, today } from '../lib/dates';
import { getErrorMessage } from '../lib/errors';
import { formatMoney } from '../lib/money';

const TRACKING = [
  { value: 'serialized', label: 'Phones tracked by IMEI / serial number' },
  { value: 'quantity', label: 'Counted items (accessories) with average cost' },
  { value: 'direct', label: 'Sold directly, no stock kept' },
];
const TRACKING_LABEL = { serialized: 'By IMEI', quantity: 'Counted', direct: 'Direct sale' };
const STATUS_TONE = { in_stock: 'good', sold: 'info', written_off: 'bad', reserved: 'warn', returned_to_supplier: 'neutral' };
const STATUS_LABEL = { in_stock: 'In stock', sold: 'Sold', written_off: 'Written off', reserved: 'Reserved', returned_to_supplier: 'Returned to supplier' };
const CONDITIONS = [{ value: 'new', label: 'New' }, { value: 'like_new', label: 'Like new' }, { value: 'used', label: 'Used' }, { value: 'refurbished', label: 'Refurbished' }, { value: 'for_parts', label: 'For parts' }];

export default function InventoryPage() {
  const toast = useToast();
  const [tab, setTab] = useState('products');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const debounced = useDebounce(search);

  const summary = useFetch(() => client.get('/api/inventory/summary').then((r) => r.data.data), []);
  useDataChanged(summary.reload);
  const products = usePagedList('/api/products', { search: debounced }, { enabled: tab === 'products' });
  const units = usePagedList('/api/inventory/units', { search: debounced, status }, { enabled: tab === 'phones' });

  const [productForm, setProductForm] = useState(null);
  const [purchase, setPurchase] = useState(false);
  const [writeOff, setWriteOff] = useState(null);   // { unit } or { product }
  const [unitForm, setUnitForm] = useState(null);

  const refresh = () => { products.reload(); units.reload(); summary.reload(); announceDataChanged(); };
  const totals = summary.data?.totals;

  const productFields = (editing) => [
    { name: 'product_type', label: 'Kind', type: 'select', required: true, options: [{ value: 'phone', label: 'Phone' }, { value: 'accessory', label: 'Accessory' }, { value: 'other', label: 'Other' }] },
    { name: 'tracking', label: 'How you keep stock', type: 'select', required: true, options: TRACKING, disabled: editing, hint: editing ? 'Cannot change once the product exists.' : 'Phones are tracked one by one so the same IMEI can never be sold twice.' },
    { name: 'name', label: 'Name', required: true, placeholder: 'e.g. iPhone 13' },
    { name: 'brand', label: 'Brand (optional)' },
    { name: 'model', label: 'Model (optional)' },
    { name: 'sku', label: 'Code / SKU (optional)' },
    { name: 'default_selling_price', label: 'Usual selling price (optional)', type: 'money', allowZero: true },
    { name: 'reorder_level', label: 'Warn me when stock is at or below', show: (v) => v.tracking === 'quantity', hint: 'Counted items only.' },
    ...(editing ? [{ name: 'is_active', label: 'Active', type: 'checkbox' }] : []),
    { name: 'notes', label: 'Notes (optional)', type: 'textarea' },
  ];

  const productColumns = [
    { key: 'name', header: 'Product', sortable: true, render: (p) => <div><div className="font-medium">{p.name}</div><div className="text-xs text-slate-500">{[p.brand, p.model, p.sku].filter(Boolean).join(' · ')}</div></div> },
    { key: 'tracking', header: 'Tracking', render: (p) => <Badge>{TRACKING_LABEL[p.tracking]}</Badge> },
    { key: 'stock', header: 'In stock', align: 'right', sortValue: (p) => String(p.stock?.quantity ?? 0).padStart(9, '0'), sortable: true, render: (p) => (p.tracking === 'direct' ? '—' : <span>{p.stock?.quantity ?? 0} {p.stock?.low_stock && <Badge tone="warn">Low</Badge>}</span>) },
    { key: 'value', header: 'Value at cost', align: 'right', sortValue: (p) => p.stock?.value_at_cost, sortable: true, render: (p) => (p.tracking === 'direct' ? '—' : formatMoney(p.stock?.value_at_cost)) },
    { key: 'is_active', header: 'Status', render: (p) => (p.is_active ? <Badge tone="good">Active</Badge> : <Badge>Inactive</Badge>) },
    { key: 'actions', header: '', render: (p) => (
      <div className="flex justify-end gap-1">
        <Button size="sm" onClick={() => setProductForm({ product: p })}>Edit</Button>
        {p.tracking === 'quantity' && (p.stock?.quantity ?? 0) > 0 && <Button size="sm" variant="ghost" onClick={() => setWriteOff({ product: p })}>Write off</Button>}
      </div>
    ) },
  ];

  const unitColumns = [
    { key: 'imei', header: 'IMEI / serial', sortable: true, render: (u) => <div><div className="font-mono text-xs">{u.imei || u.serial_number || `#${u.id}`}</div><div className="text-xs text-slate-500">{u.imei && u.serial_number ? u.serial_number : ''}</div></div> },
    { key: 'product', header: 'Phone', sortValue: (u) => u.product?.name || '', sortable: true, render: (u) => <div><div>{u.product?.name}</div><div className="text-xs text-slate-500">{[u.storage, u.color, u.item_condition.replace('_', ' ')].filter(Boolean).join(' · ')}</div></div> },
    { key: 'purchase_date', header: 'Bought', render: (u) => <div><div>{formatDate(u.purchase_date)}</div><div className="text-xs text-slate-500">{u.supplier?.name || (u.acquisition_source === 'opening_stock' ? 'Opening stock' : '')}</div></div> },
    { key: 'cost', header: 'Cost', align: 'right', sortable: true, render: (u) => formatMoney(u.cost) },
    { key: 'status', header: 'Status', render: (u) => <Badge tone={STATUS_TONE[u.status]}>{STATUS_LABEL[u.status]}</Badge> },
    { key: 'profit', header: 'Sold', align: 'right', render: (u) => (u.sale ? <div><div>{u.sale.sale_number}</div><div className="text-xs text-green-600">profit {formatMoney(u.sale.gross_profit)}</div></div> : '') },
    { key: 'actions', header: '', render: (u) => (
      <div className="flex justify-end gap-1">
        <Button size="sm" onClick={() => setUnitForm(u)}>Edit</Button>
        {u.status === 'in_stock' && <Button size="sm" variant="ghost" onClick={() => setWriteOff({ unit: u })}>Write off</Button>}
      </div>
    ) },
  ];

  const target = writeOff?.unit ? { inventory_unit_id: writeOff.unit.id } : writeOff ? { product_id: writeOff.product.id } : {};

  return (
    <div>
      <PageHeader
        title="Inventory"
        subtitle="Phones and accessories in stock, valued at what they cost you. Stock is an asset: it only becomes a cost when it is sold."
        actions={<><Button onClick={() => setProductForm({})}>＋ Add product</Button><Button variant="primary" onClick={() => setPurchase(true)}>＋ Buy stock</Button></>}
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <StatCard title="Stock value at cost" value={totals && formatMoney(totals.stock_value_at_cost)} loading={summary.loading && !totals} />
        <StatCard title="Phones in stock" value={totals && String(totals.phones_in_stock)} loading={summary.loading && !totals} />
        <StatCard title="Counted items in stock" value={totals && String(totals.counted_items_in_stock)} loading={summary.loading && !totals} />
      </div>

      {summary.error && !summary.data && <ErrorBanner message={getErrorMessage(summary.error)} onRetry={summary.reload} />}
      {summary.data?.low_stock?.length > 0 && (
        <div role="alert" className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
          <strong>Low stock:</strong> {summary.data.low_stock.map((i) => `${i.name} (${i.quantity} left)`).join(', ')}
        </div>
      )}

      <Tabs tabs={[{ key: 'products', label: 'Products' }, { key: 'phones', label: 'Phones (by IMEI)' }, { key: 'sold', label: 'Products sold' }]} value={tab} onChange={(t) => { setTab(t); setSearch(''); setStatus(''); }} />

      {tab !== 'sold' && (
        <div className="mb-3 grid gap-2 sm:grid-cols-3">
          <input className="input sm:col-span-2" type="search" aria-label="Search" placeholder={tab === 'products' ? 'Search name, brand, model or code…' : 'Search IMEI, serial number, storage or colour…'} value={search} onChange={(e) => setSearch(e.target.value)} />
          {tab === 'phones' && (
            <select className="input" aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">All statuses</option>
              {Object.entries(STATUS_LABEL).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          )}
        </div>
      )}

      {tab === 'products' && (
        <DataTable columns={productColumns} rows={products.rows} loading={products.loading} error={products.error && getErrorMessage(products.error)} onRetry={products.reload}
          emptyTitle="No products yet" emptyHint="Add the models you sell, then use “Buy stock” to record what you bought."
          emptyAction={<Button variant="primary" onClick={() => setProductForm({})}>＋ Add your first product</Button>}
          page={products.page} lastPage={products.lastPage} total={products.total} onPage={products.setPage} caption="Products" />
      )}
      {tab === 'phones' && (
        <DataTable columns={unitColumns} rows={units.rows} loading={units.loading} error={units.error && getErrorMessage(units.error)} onRetry={units.reload}
          emptyTitle="No phones recorded" emptyHint="Phones appear here when you buy stock for a product tracked by IMEI."
          page={units.page} lastPage={units.lastPage} total={units.total} onPage={units.setPage} caption="Phones" />
      )}
      {tab === 'sold' && (
        <DataTable
          columns={[
            { key: 'name', header: 'Product', sortable: true },
            { key: 'quantity_sold', header: 'Quantity sold', align: 'right', sortable: true },
            { key: 'sales_before_discount', header: 'Sales (before discount)', align: 'right', sortable: true, render: (r) => formatMoney(r.sales_before_discount) },
          ]}
          rows={(summary.data?.products_sold || []).map((r) => ({ ...r, id: r.product_id }))} loading={summary.loading} error={summary.error && getErrorMessage(summary.error)} onRetry={summary.reload}
          emptyTitle="Nothing sold yet" emptyHint="Completed sales appear here, net of returns." caption="Products sold" />
      )}

      {productForm && (
        <FormModal title={productForm.product ? 'Edit product' : 'Add product'} fields={productFields(!!productForm.product)}
          initial={productForm.product ? { ...productForm.product, reorder_level: productForm.product.reorder_level ?? '', default_selling_price: productForm.product.default_selling_price ?? '' } : { product_type: 'phone', tracking: 'serialized' }}
          onClose={() => setProductForm(null)} submitLabel={productForm.product ? 'Save changes' : 'Add product'}
          onSubmit={async (values) => {
            if (productForm.product) await client.put(`/api/products/${productForm.product.id}`, values);
            else await client.post('/api/products', values);
            toast.success(productForm.product ? 'Product updated.' : 'Product added.');
            refresh();
          }} />
      )}

      {purchase && <PurchaseForm onClose={() => setPurchase(false)} onDone={refresh} />}

      {writeOff && (
        <FormModal
          title={writeOff.unit ? `Write off ${writeOff.unit.imei || 'this phone'}` : `Write off ${writeOff.product.name}`}
          description="Use this for stock that is lost, broken or stolen. Its cost leaves your inventory and counts as a loss in profit."
          fields={[
            { name: 'quantity', label: 'Quantity', required: true, defaultValue: '1', show: () => !!writeOff.product },
            { name: 'write_off_date', label: 'Date', type: 'date', required: true, max: today(), defaultValue: today() },
            { name: 'reason', label: 'Reason', required: true, placeholder: 'e.g. Dropped and broken' },
          ]}
          submitLabel="Write off" onClose={() => setWriteOff(null)}
          onSubmit={async (values) => {
            const response = await client.post('/api/inventory/write-offs', { ...target, ...values });
            toast.warning(`Written off: loss of ${formatMoney(response.data.data.cost)}.`);
            refresh();
          }} />
      )}

      {unitForm && (
        <FormModal title="Edit phone details" description="The cost cannot be edited here: it follows the stock purchase."
          fields={[
            { name: 'imei', label: 'IMEI', hint: unitForm.status === 'in_stock' ? undefined : 'Can only be changed while the phone is in stock.', disabled: unitForm.status !== 'in_stock' },
            { name: 'serial_number', label: 'Serial number', disabled: unitForm.status !== 'in_stock' },
            { name: 'storage', label: 'Storage' }, { name: 'color', label: 'Colour' },
            { name: 'item_condition', label: 'Condition', type: 'select', required: true, options: CONDITIONS },
            { name: 'notes', label: 'Notes', type: 'textarea' },
          ]}
          initial={{ ...unitForm, item_condition: unitForm.item_condition }} onClose={() => setUnitForm(null)}
          onSubmit={async (values) => { await client.put(`/api/inventory/units/${unitForm.id}`, values); toast.success('Phone updated.'); refresh(); }} />
      )}
    </div>
  );
}
