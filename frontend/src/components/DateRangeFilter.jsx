import { useState } from 'react';
import { useI18n } from '../i18n/I18nContext';
import { PRESETS, presetRange } from '../lib/dates';

/** Today / this week / this month / this year / custom range. value = { preset, from, to }. */
export default function DateRangeFilter({ value, onChange }) {
  const { t } = useI18n();
  const [custom, setCustom] = useState({ from: value.from, to: value.to });
  const invalid = custom.from && custom.to && custom.from > custom.to;

  const choose = (key) => {
    if (key === 'custom') {
      onChange({ preset: 'custom', from: custom.from, to: custom.to });
    } else {
      onChange({ preset: key, ...presetRange(key) });
    }
  };

  const editCustom = (patch) => {
    const next = { ...custom, ...patch };
    setCustom(next);

    if (next.from && next.to && next.from <= next.to) onChange({ preset: 'custom', ...next });
  };

  return (
    <div className="flex flex-wrap items-end gap-2" role="group" aria-label={t('range.label')}>
      <div className="inline-flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1 dark:bg-white/[0.05]">
        {PRESETS.map((preset) => (
          <button
            key={preset.key}
            type="button"
            aria-pressed={value.preset === preset.key}
            onClick={() => choose(preset.key)}
            className={`btn btn-sm !rounded-lg ${value.preset === preset.key ? 'bg-white text-brand-700 shadow-sm dark:bg-brand-500/25 dark:text-white' : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'}`}
          >
            {t(`range.${preset.key}`)}
          </button>
        ))}
      </div>
      {value.preset === 'custom' && (
        <div className="flex flex-wrap items-end gap-2">
          <label className="text-xs">
            {t('range.from')}
            <input type="date" className="input mt-0.5 py-1" value={custom.from} onChange={(e) => editCustom({ from: e.target.value })} aria-invalid={invalid || undefined} />
          </label>
          <label className="text-xs">
            {t('range.to')}
            <input type="date" className="input mt-0.5 py-1" value={custom.to} onChange={(e) => editCustom({ to: e.target.value })} aria-invalid={invalid || undefined} />
          </label>
          {invalid && <p role="alert" className="text-xs text-red-600">{t('range.invalid')}</p>}
        </div>
      )}
    </div>
  );
}
