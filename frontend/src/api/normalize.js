/**
 * The API returns two kinds of pages: Laravel resource collections ({ data, meta: { current_page, ... } })
 * and plain paginators ({ data, current_page, ... }). This gives both the same shape.
 */
export function normalizePage(json) {
  const source = json?.meta?.current_page !== undefined ? json.meta : json || {};
  const rows = json?.data || [];

  return {
    rows,
    page: source.current_page || 1,
    lastPage: source.last_page || 1,
    total: source.total ?? rows.length,
    perPage: source.per_page || rows.length,
    meta: json?.meta || {},
  };
}

/** Removes empty values so they are not sent as filters. */
export function cleanParams(params) {
  return Object.fromEntries(
    Object.entries(params || {}).filter(([, value]) => value !== '' && value !== null && value !== undefined),
  );
}
