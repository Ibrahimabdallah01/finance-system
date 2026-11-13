import { useState } from 'react';
import client from '../api/client';
import ConfirmDialog from '../components/ConfirmDialog';
import DataTable from '../components/DataTable';
import FormModal from '../components/FormModal';
import { Badge, Button, EmptyState, ErrorBanner, PageHeader, Skeleton, Tabs } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useLookups } from '../context/LookupContext';
import { useTheme } from '../context/ThemeContext';
import { useToast } from '../context/ToastContext';
import { announceDataChanged } from '../hooks/useDataChanged';
import { useFetch } from '../hooks/useFetch';
import { usePagedList } from '../hooks/usePagedList';
import { getErrorMessage } from '../lib/errors';

function Section({ title, subtitle, children, actions }) {
  return (
    <section className="card mb-4">
      <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div><h2 className="font-semibold">{title}</h2>{subtitle && <p className="text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>}</div>
        {actions}
      </div>
      {children}
    </section>
  );
}

function CategoryRow({ category, child = false, onSub, onRename, onToggle, onDelete }) {
  return (
    <li className={`flex flex-wrap items-center justify-between gap-2 py-1.5 ${child ? 'pl-6' : ''}`}>
      <span className={category.is_active ? '' : 'text-slate-400 line-through'}>{category.name} {!category.is_active && <Badge>Inactive</Badge>}</span>
      <span className="flex gap-1">
        {!child && <Button size="sm" variant="ghost" onClick={() => onSub(category)}>＋ Subcategory</Button>}
        <Button size="sm" variant="ghost" onClick={() => onRename(category)}>Rename</Button>
        <Button size="sm" variant="ghost" onClick={() => onToggle(category)}>{category.is_active ? 'Deactivate' : 'Activate'}</Button>
        <Button size="sm" variant="ghost" onClick={() => onDelete(category)}>Delete</Button>
      </span>
    </li>
  );
}

function Categories() {
  const toast = useToast();
  const { reload: reloadLookups } = useLookups();
  const [scope, setScope] = useState('personal');
  const [kind, setKind] = useState('expense');
  const { data, loading, error, reload } = useFetch(() => client.get('/api/categories', { params: { scope, kind } }).then((r) => r.data.data), [scope, kind]);
  const [form, setForm] = useState(null);   // { mode: 'add' | 'sub' | 'rename', category? }
  const [remove, setRemove] = useState(null);
  const refresh = () => { reload(); reloadLookups(); announceDataChanged(); };

  const toggle = async (category) => {
    try { await client.put(`/api/categories/${category.id}`, { is_active: !category.is_active }); refresh(); }
    catch (err) { toast.error(getErrorMessage(err)); }
  };

  const handlers = {
    onSub: (category) => setForm({ mode: 'sub', category }),
    onRename: (category) => setForm({ mode: 'rename', category }),
    onToggle: toggle,
    onDelete: setRemove,
  };

  return (
    <Section title="Categories" subtitle="Group your income and spending. Deactivate a category you no longer use instead of deleting it." actions={<Button onClick={() => setForm({ mode: 'add' })}>＋ Add category</Button>}>
      <Tabs tabs={[{ key: 'personal', label: 'Personal' }, { key: 'business', label: 'Business' }]} value={scope} onChange={setScope} />
      <div className="mb-3 flex gap-1" role="group" aria-label="Kind">
        {[['expense', 'Expenses'], ['income', 'Income']].map(([key, label]) => (
          <button key={key} type="button" aria-pressed={kind === key} onClick={() => setKind(key)} className={`btn btn-sm ${kind === key ? 'bg-brand-600 text-white' : 'border border-slate-300 dark:border-slate-600'}`}>{label}</button>
        ))}
      </div>
      {error && <ErrorBanner message={getErrorMessage(error)} onRetry={reload} />}
      {loading && !data && <Skeleton className="h-24" />}
      {data && data.length === 0 && <EmptyState title="No categories here" hint="Add one to start grouping your records." />}
      <ul className="divide-y divide-slate-100 dark:divide-slate-700">
        {(data || []).map((category) => (
          <li key={category.id} className="list-none">
            <ul>
              <CategoryRow category={category} {...handlers} />
              {category.children.map((child) => <CategoryRow key={child.id} category={child} child {...handlers} />)}
            </ul>
          </li>
        ))}
      </ul>

      {form?.mode === 'add' && (
        <FormModal title="Add category" initial={{ scope, kind }} submitLabel="Add category" onClose={() => setForm(null)}
          fields={[
            { name: 'scope', label: 'For', type: 'select', required: true, options: [{ value: 'personal', label: 'Personal' }, { value: 'business', label: 'Business' }] },
            { name: 'kind', label: 'Kind', type: 'select', required: true, options: [{ value: 'expense', label: 'Expense' }, { value: 'income', label: 'Income' }] },
            { name: 'name', label: 'Name', required: true },
          ]}
          onSubmit={async (values) => { await client.post('/api/categories', values); toast.success('Category added.'); refresh(); }} />
      )}
      {form?.mode === 'sub' && (
        <FormModal title={`Add subcategory to ${form.category.name}`} submitLabel="Add subcategory" onClose={() => setForm(null)}
          fields={[{ name: 'name', label: 'Name', required: true }]}
          onSubmit={async (values) => { await client.post('/api/categories', { ...values, scope: form.category.scope, kind: form.category.kind, parent_id: form.category.id }); toast.success('Subcategory added.'); refresh(); }} />
      )}
      {form?.mode === 'rename' && (
        <FormModal title="Rename category" initial={{ name: form.category.name }} submitLabel="Save" onClose={() => setForm(null)}
          fields={[{ name: 'name', label: 'Name', required: true }]}
          onSubmit={async (values) => { await client.put(`/api/categories/${form.category.id}`, values); toast.success('Category renamed.'); refresh(); }} />
      )}
      {remove && <ConfirmDialog title="Delete category" message={`Delete "${remove.name}"? This only works for a category that was never used. Otherwise deactivate it.`} confirmLabel="Delete" danger onCancel={() => setRemove(null)}
        onConfirm={async () => { await client.delete(`/api/categories/${remove.id}`); toast.success('Category deleted.'); refresh(); }} />}
    </Section>
  );
}

function Contacts({ title, path, singular, extraFields = [] }) {
  const toast = useToast();
  const list = usePagedList(path, {});
  const [form, setForm] = useState(null);
  const [remove, setRemove] = useState(null);
  const fields = [
    { name: 'name', label: 'Name', required: true },
    { name: 'phone', label: 'Phone (optional)' },
    ...extraFields,
    { name: 'notes', label: 'Notes (optional)', type: 'textarea' },
  ];
  const columns = [
    { key: 'name', header: 'Name', sortable: true },
    { key: 'phone', header: 'Phone', render: (r) => r.phone || '' },
    ...(extraFields.length ? [{ key: 'email', header: 'Email', render: (r) => r.email || '' }] : []),
    { key: 'actions', header: '', render: (r) => (
      <div className="flex justify-end gap-1"><Button size="sm" onClick={() => setForm({ row: r })}>Edit</Button><Button size="sm" variant="ghost" onClick={() => setRemove(r)}>Delete</Button></div>
    ) },
  ];

  return (
    <Section title={title} actions={<Button onClick={() => setForm({})}>＋ Add {singular}</Button>}>
      <DataTable columns={columns} rows={list.rows} loading={list.loading} error={list.error && getErrorMessage(list.error)} onRetry={list.reload}
        emptyTitle={`No ${title.toLowerCase()} yet`} page={list.page} lastPage={list.lastPage} total={list.total} onPage={list.setPage} caption={title} />
      {form && (
        <FormModal title={form.row ? `Edit ${singular}` : `Add ${singular}`} fields={fields} initial={form.row} submitLabel={form.row ? 'Save changes' : `Add ${singular}`} onClose={() => setForm(null)}
          onSubmit={async (values) => {
            if (form.row) await client.put(`${path}/${form.row.id}`, values); else await client.post(path, values);
            toast.success(`${singular[0].toUpperCase()}${singular.slice(1)} saved.`);
            list.reload();
          }} />
      )}
      {remove && <ConfirmDialog title={`Delete ${singular}`} message={`Delete "${remove.name}"? This only works if there is no stock, sale or debt linked to them.`} confirmLabel="Delete" danger onCancel={() => setRemove(null)}
        onConfirm={async () => { await client.delete(`${path}/${remove.id}`); toast.success(`${singular[0].toUpperCase()}${singular.slice(1)} deleted.`); list.reload(); }} />}
    </Section>
  );
}

export default function SettingsPage() {
  const { user } = useAuth();
  const { theme, toggle } = useTheme();

  return (
    <div>
      <PageHeader title="Settings" subtitle="Your profile, appearance, categories and contacts." />

      <Section title="Profile">
        <dl className="grid grid-cols-3 gap-y-1.5 text-sm">
          <dt className="text-slate-500">Name</dt><dd className="col-span-2">{user?.name}</dd>
          <dt className="text-slate-500">Email</dt><dd className="col-span-2">{user?.email}</dd>
          <dt className="text-slate-500">Business</dt><dd className="col-span-2">{user?.business?.name}</dd>
          <dt className="text-slate-500">Currency</dt><dd className="col-span-2">{user?.business?.currency} (TSh)</dd>
        </dl>
      </Section>

      <Section title="Appearance">
        <div className="flex items-center gap-3 text-sm">
          <span>Current mode: <strong>{theme === 'dark' ? 'Dark' : 'Light'}</strong></span>
          <Button onClick={toggle}>Switch to {theme === 'dark' ? 'light' : 'dark'} mode</Button>
        </div>
      </Section>

      <Categories />
      <Contacts title="Suppliers" path="/api/suppliers" singular="supplier" />
      <Contacts title="Customers" path="/api/customers" singular="customer" extraFields={[{ name: 'email', label: 'Email (optional)' }]} />

      <Section title="Backup and restore">
        <p className="text-sm text-slate-600 dark:text-slate-300">A step-by-step backup and restore procedure for your database arrives in Phase 10, together with the deployment notes.</p>
      </Section>
    </div>
  );
}
