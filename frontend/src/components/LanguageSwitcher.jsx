import { useEffect, useRef, useState } from 'react';
import { useI18n } from '../i18n/I18nContext';
import Icon from './Icon';

/** Header dropdown with the five languages. The choice is remembered. */
export default function LanguageSwitcher() {
  const { lang, setLang, languages, t } = useI18n();
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const current = languages.find((l) => l.code === lang) || languages[0];

  useEffect(() => {
    if (!open) return undefined;
    const close = (event) => { if (!ref.current?.contains(event.target)) setOpen(false); };
    const key = (event) => { if (event.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', key);
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', key); };
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <button type="button" className="btn-secondary btn-sm !rounded-xl !px-3 !py-2" aria-haspopup="menu" aria-expanded={open} aria-label={`${t('header.language')}: ${current.label}`} onClick={() => setOpen((o) => !o)}>
        <Icon name="globe" className="h-4 w-4" />
        <span className="font-semibold">{current.short}</span>
        <Icon name="chevron" className={`h-3.5 w-3.5 transition ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <ul role="menu" aria-label={t('header.language')} className="absolute end-0 z-50 mt-2 w-44 animate-scale-in overflow-hidden rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-white/10 dark:bg-ink-700">
          {languages.map((l) => (
            <li key={l.code} role="none">
              <button
                type="button" role="menuitemradio" aria-checked={l.code === lang} lang={l.code} dir={l.dir}
                onClick={() => { setLang(l.code); setOpen(false); }}
                className={`flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm ${l.code === lang ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' : 'hover:bg-slate-100 dark:hover:bg-white/[0.06]'}`}
              >
                <span>{l.label}</span>
                {l.code === lang ? <Icon name="check" className="h-4 w-4" /> : <span className="text-xs text-slate-400">{l.short}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
