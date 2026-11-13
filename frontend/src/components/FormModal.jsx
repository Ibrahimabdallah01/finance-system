import { useMemo, useState } from 'react';
import { useI18n } from '../i18n/I18nContext';
import Field from './Field';
import { Button, ErrorBanner, Modal } from './ui';
import { getErrorMessage, getFieldErrors } from '../lib/errors';
import { isValidAmount } from '../lib/money';

const resolve = (value, values) => (typeof value === 'function' ? value(values) : value);

function startingValues(fields, initial) {
  const values = {};

  for (const field of fields) {
    const given = initial?.[field.name];
    values[field.name] = given ?? (field.type === 'checkbox' ? false : (field.defaultValue ?? ''));
  }

  return values;
}

/**
 * A form built from a list of fields. Required fields and amounts are checked first;
 * messages from the API (422) are shown next to the field they belong to.
 *
 * field: { name, label, type, options, required, hint, placeholder, show, allowZero, allowNegative, defaultValue, disabled }
 * `options`, `required`, `hint`, `show` and `disabled` may be functions of the current values.
 */
export default function FormModal({ title, description, fields, initial, onSubmit, onClose, submitLabel, wide = false }) {
  const { t } = useI18n();
  const [values, setValues] = useState(() => startingValues(fields, initial));
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);

  const visible = useMemo(() => fields.filter((field) => resolve(field.show, values) !== false), [fields, values]);

  const change = (name, value) => {
    setValues((current) => ({ ...current, [name]: value }));
    setErrors((current) => (current[name] ? { ...current, [name]: undefined } : current));
  };

  const validate = () => {
    const found = {};

    for (const field of visible) {
      const value = values[field.name];
      const required = resolve(field.required, values);
      const empty = value === '' || value === null || value === undefined;

      if (required && field.type !== 'checkbox' && empty) {
        found[field.name] = t('common.required');
      } else if (field.type === 'money' && !empty && !isValidAmount(value, { allowZero: field.allowZero, allowNegative: field.allowNegative })) {
        found[field.name] = field.allowZero
          ? 'Enter an amount with at most 2 decimals, for example 15000 or 15000.50.'
          : 'Enter an amount greater than zero with at most 2 decimals, for example 15000 or 15000.50.';
      }
    }

    return found;
  };

  const submit = async (event) => {
    event.preventDefault();
    setFormError('');

    const found = validate();
    setErrors(found);
    if (Object.keys(found).length > 0) return;

    setBusy(true);

    try {
      // Only fields that are visible are sent.
      const payload = Object.fromEntries(visible.map((field) => [field.name, values[field.name]]));
      await onSubmit(payload);
      onClose();
    } catch (error) {
      const fieldErrors = getFieldErrors(error);
      const known = Object.keys(fieldErrors).filter((name) => visible.some((field) => field.name === name));

      setErrors(fieldErrors);
      // Errors for fields the form does not show (for example account_id on a hidden field) still reach the person.
      if (known.length === 0 || known.length < Object.keys(fieldErrors).length) setFormError(getErrorMessage(error));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title={title} onClose={onClose} wide={wide} size={visible.length >= 6 ? 'lg' : 'md'}>
      <form onSubmit={submit} noValidate className="space-y-3">
        {description && <p className="text-sm text-slate-600 dark:text-slate-300">{description}</p>}
        {formError && <ErrorBanner message={formError} />}
        <div className={wide || visible.length >= 6 ? 'grid gap-3 sm:grid-cols-2' : 'space-y-3'}>
          {visible.map((field) => (
            <Field
              key={field.name}
              name={field.name}
              label={field.label}
              type={field.type}
              value={values[field.name]}
              onChange={change}
              error={errors[field.name]}
              hint={resolve(field.hint, values)}
              required={resolve(field.required, values)}
              options={resolve(field.options, values)}
              placeholder={field.placeholder}
              disabled={resolve(field.disabled, values)}
              max={field.max}
              min={field.min}
            />
          ))}
        </div>
        <div className="flex justify-end gap-2 pt-2">
          <Button onClick={onClose} disabled={busy}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" loading={busy}>
            {submitLabel ?? t('common.save')}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
