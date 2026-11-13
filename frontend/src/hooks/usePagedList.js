import { useEffect, useState } from 'react';
import client from '../api/client';
import { cleanParams, normalizePage } from '../api/normalize';
import { useDataChanged } from './useDataChanged';
import { useFetch } from './useFetch';

/** A server-paged list. Filters go in `params`; changing them returns to page 1. */
export function usePagedList(path, params = {}, { perPage = 25, enabled = true } = {}) {
  const [page, setPage] = useState(1);
  const key = JSON.stringify(cleanParams(params));

  useEffect(() => {
    setPage(1);
  }, [key, path]);

  const result = useFetch(
    () => (enabled
      ? client.get(path, { params: { ...cleanParams(params), page, per_page: perPage } }).then((response) => normalizePage(response.data))
      : Promise.resolve(normalizePage({ data: [] }))),
    [path, key, page, perPage, enabled],
  );
  useDataChanged(result.reload);

  const current = result.data;

  return {
    rows: current?.rows || [],
    page: current?.page || page,
    lastPage: current?.lastPage || 1,
    total: current?.total ?? 0,
    meta: current?.meta || {},
    loading: result.loading,
    error: result.error,
    reload: result.reload,
    setPage,
  };
}
