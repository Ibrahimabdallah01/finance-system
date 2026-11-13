import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { setDateLocale } from '../lib/dates';
import { LANGUAGES, TRANSLATIONS } from './translations';

const STORAGE_KEY = 'language';

/** Looks a word up in the chosen language, falls back to English, and fills {placeholders}. */
export function translate(lang, key, vars) {
  const text = TRANSLATIONS[lang]?.[key] ?? TRANSLATIONS.en[key] ?? key;

  return vars ? text.replace(/\{(\w+)\}/g, (match, name) => (vars[name] !== undefined ? String(vars[name]) : match)) : text;
}

const fallback = { lang: 'en', setLang: () => {}, languages: LANGUAGES, dir: 'ltr', t: (key, vars) => translate('en', key, vars) };
const I18nContext = createContext(fallback);

function initialLanguage() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved && TRANSLATIONS[saved]) return saved;
  } catch {
    /* storage unavailable */
  }

  const browser = (typeof navigator !== 'undefined' ? navigator.language : 'en').slice(0, 2);

  return TRANSLATIONS[browser] ? browser : 'en';
}

export function I18nProvider({ children }) {
  const [lang, setLangState] = useState(initialLanguage);
  const meta = LANGUAGES.find((l) => l.code === lang) || LANGUAGES[0];

  useEffect(() => {
    document.documentElement.lang = lang;
    document.documentElement.dir = meta.dir;
    setDateLocale(lang === 'en' ? null : meta.locale);
  }, [lang, meta]);

  const setLang = useCallback((code) => {
    if (!TRANSLATIONS[code]) return;
    setLangState(code);
    try {
      localStorage.setItem(STORAGE_KEY, code);
    } catch {
      /* storage unavailable */
    }
  }, []);

  const t = useCallback((key, vars) => translate(lang, key, vars), [lang]);
  const value = useMemo(() => ({ lang, setLang, languages: LANGUAGES, dir: meta.dir, t }), [lang, setLang, meta, t]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

/** Works without a provider too (English), so small components can be used and tested on their own. */
export function useI18n() {
  return useContext(I18nContext);
}
