import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import client from '../api/client';
import { useAuth } from './AuthContext';

const LookupContext = createContext(null);

/** Turns the category tree into a flat list; subcategories are labelled "Parent › Child". */
export function flattenCategories(tree = []) {
  const flat = [];

  for (const parent of tree) {
    flat.push({ id: parent.id, name: parent.name, label: parent.name, scope: parent.scope, kind: parent.kind, parent_id: null, is_active: parent.is_active });

    for (const child of parent.children || []) {
      flat.push({ id: child.id, name: child.name, label: `${parent.name} › ${child.name}`, scope: child.scope, kind: child.kind, parent_id: parent.id, is_active: child.is_active });
    }
  }

  return flat;
}

/** Accounts and categories are needed by many forms, so they are loaded once and refreshed after changes. */
export function LookupProvider({ children }) {
  const { user } = useAuth();
  const [state, setState] = useState({ accounts: [], categories: [], loading: true });

  const reload = useCallback(async () => {
    try {
      const [accounts, categories] = await Promise.all([
        client.get('/api/accounts', { params: { active: 1 } }),
        client.get('/api/categories', { params: { active: 1 } }),
      ]);
      setState({ accounts: accounts.data.data, categories: flattenCategories(categories.data.data), loading: false });
    } catch {
      setState((s) => ({ ...s, loading: false }));
    }
  }, []);

  useEffect(() => {
    if (user) reload();
  }, [user, reload]);

  const value = useMemo(() => ({ ...state, reload }), [state, reload]);

  return <LookupContext.Provider value={value}>{children}</LookupContext.Provider>;
}

export function useLookups() {
  const value = useContext(LookupContext);
  if (!value) throw new Error('useLookups must be used inside LookupProvider');
  return value;
}
