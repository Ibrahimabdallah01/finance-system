import { useId } from 'react';

/**
 * One form field with its label, hint and error message.
 * type: text | email | password | money | date | select | textarea | checkbox
 */
export default function Field({ label, name, type = 'text', value, onChange, error, hint, required, options = [], placeholder, rows = 3, disabled, max, min }) {
  const id = useId();
  const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined;
  const common = {
    id,
    name,
    disabled,
    required,
    'aria-invalid': error ? true : undefined,
    'aria-describedby': describedBy,
  };
  const set = (event) => onChange(name, event.target.type === 'checkbox' ? event.target.checked : event.target.value);
  const cls = `input ${error ? 'input-error' : ''}`;

  if (type === 'checkbox') {
    return (
      <div>
        <label htmlFor={id} className="flex items-center gap-2 text-sm">
          <input {...common} type="checkbox" checked={!!value} onChange={set} className="h-4 w-4 rounded border-slate-300 text-brand-600" />
          {label}
        </label>
        {hint && <p id={`${id}-hint`} className="mt-1 text-xs text-slate-500 dark:text-slate-400">{hint}</p>}
        {error && <p id={`${id}-error`} className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
      </div>
    );
  }

  let control;

  if (type === 'select') {
    control = (
      <select {...common} value={value ?? ''} onChange={set} className={cls}>
        <option value="">{placeholder || (required ? 'Choose…' : 'None')}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    );
  } else if (type === 'textarea') {
    control = <textarea {...common} rows={rows} value={value ?? ''} onChange={set} placeholder={placeholder} className={cls} />;
  } else if (type === 'money') {
    control = <input {...common} type="text" inputMode="decimal" autoComplete="off" value={value ?? ''} onChange={set} placeholder={placeholder || '0'} className={cls} />;
  } else {
    control = <input {...common} type={type} value={value ?? ''} onChange={set} placeholder={placeholder} max={max} min={min} className={cls} />;
  }

  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium">
        {label}
        {required && <span className="text-red-600" aria-hidden="true"> *</span>}
      </label>
      {control}
      {hint && <p id={`${id}-hint`} className="mt-1 text-xs text-slate-500 dark:text-slate-400">{hint}</p>}
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs text-red-600 dark:text-red-400" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
