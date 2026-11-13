import { useMemo, useState } from 'react';
import { EmptyState, ErrorBanner, Skeleton } from './ui';
import { compareMoney } from '../lib/money';

const AMOUNT = /^-?\d+(\.\d+)?$/;

function compare(a, b) {
  if (a === b) return 0;
  if (a === null || a === undefined || a === '') return 1;
  if (b === null || b === undefined || b === '') return -1;

  const x = String(a);
  const y = String(b);

  if (AMOUNT.test(x) && AMOUNT.test(y)) return compareMoney(x, y);

  return x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });
}

export function Pagination({ page, lastPage, total, onPage }) {
  if (!onPage || (lastPage <= 1 && total === undefined)) return null;

  return (
    <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm text-slate-600 dark:text-slate-300">
      <span>
        Page {page} of {lastPage}
        {total !== undefined && ` · ${total} record${total === 1 ? '' : 's'}`}
      </span>
      <div className="flex gap-2">
        <button type="button" className="btn-secondary btn-sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Previous
        </button>
        <button type="button" className="btn-secondary btn-sm" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
          Next
        </button>
      </div>
    </div>
  );
}

/**
 * columns: [{ key, header, render?(row), sortValue?(row), sortable?, align? }]
 * Sorting applies to the rows on the current page. Paging, searching and date filters are done by the server.
 */
export default function DataTable({ columns, rows, loading, error, onRetry, emptyTitle = 'Nothing here yet', emptyHint, emptyAction, rowKey = 'id', page, lastPage, total, onPage, onRowClick, caption }) {
  const [sort, setSort] = useState({ key: null, dir: 'asc' });

  const sorted = useMemo(() => {
    if (!sort.key) return rows || [];
    const column = columns.find((c) => c.key === sort.key);
    const value = (row) => (column?.sortValue ? column.sortValue(row) : row[sort.key]);

    return [...(rows || [])].sort((a, b) => (sort.dir === 'asc' ? 1 : -1) * compare(value(a), value(b)));
  }, [rows, sort, columns]);

  const toggle = (key) => setSort((s) => (s.key === key ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' }));

  if (error && !(rows && rows.length)) return <ErrorBanner message={error} onRetry={onRetry} />;

  return (
    <div className="card overflow-hidden p-0">
      {error && <div className="p-3"><ErrorBanner message={error} onRetry={onRetry} /></div>}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
          {caption && <caption className="sr-only">{caption}</caption>}
          <thead className="bg-slate-50 dark:bg-slate-800/60">
            <tr>
              {columns.map((column) => {
                const active = sort.key === column.key;

                return (
                  <th key={column.key} scope="col" className={`th ${column.align === 'right' ? 'text-right' : ''}`} aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
                    {column.sortable ? (
                      <button type="button" className="inline-flex items-center gap-1 uppercase tracking-wide hover:text-slate-800 dark:hover:text-slate-100" onClick={() => toggle(column.key)}>
                        {column.header}
                        <span aria-hidden="true">{active ? (sort.dir === 'asc' ? '▲' : '▼') : '↕'}</span>
                      </button>
                    ) : (
                      column.header
                    )}
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
            {loading && !(rows && rows.length) &&
              Array.from({ length: 5 }).map((_, i) => (
                <tr key={`skeleton-${i}`}>
                  {columns.map((column) => (
                    <td key={column.key} className="td"><Skeleton /></td>
                  ))}
                </tr>
              ))}
            {sorted.map((row) => (
              <tr
                key={row[rowKey]}
                className={`${onRowClick ? 'cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700/40' : ''} ${loading ? 'opacity-60' : ''}`}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
              >
                {columns.map((column) => (
                  <td key={column.key} className={`td ${column.align === 'right' ? 'text-right tabular-nums' : ''}`}>
                    {column.render ? column.render(row) : row[column.key]}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!loading && !error && (!rows || rows.length === 0) && <EmptyState title={emptyTitle} hint={emptyHint} action={emptyAction} />}
      <Pagination page={page} lastPage={lastPage} total={total} onPage={onPage} />
    </div>
  );
}
