import { useEffect } from 'react';

/** Runs `reload` when something elsewhere (for example Quick add) changed the data. */
export function useDataChanged(reload) {
  useEffect(() => {
    window.addEventListener('data:changed', reload);
    return () => window.removeEventListener('data:changed', reload);
  }, [reload]);
}

export function announceDataChanged() {
  window.dispatchEvent(new Event('data:changed'));
}
