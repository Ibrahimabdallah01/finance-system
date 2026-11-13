import { useState } from 'react';
import { useI18n } from '../i18n/I18nContext';
import { Button, ErrorBanner, Modal } from './ui';
import { getErrorMessage } from '../lib/errors';

/** Asks before anything destructive happens. With askReason, a reason of at least 3 characters is required. */
export default function ConfirmDialog({ title, message, confirmLabel, danger = false, askReason = false, reasonLabel, onConfirm, onCancel }) {
  const { t } = useI18n();
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const confirm = async () => {
    setBusy(true);
    setError('');

    try {
      await onConfirm(reason.trim());
      onCancel();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title={title} onClose={busy ? () => {} : onCancel}>
      <div className="space-y-3">
        <p className="text-sm">{message}</p>
        {askReason && (
          <div>
            <label htmlFor="confirm-reason" className="mb-1 block text-sm font-medium">{reasonLabel ?? t('common.reason')}</label>
            <input id="confirm-reason" className="input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('common.reasonHint')} />
          </div>
        )}
        {error && <ErrorBanner message={error} />}
        <div className="flex justify-end gap-2">
          <Button onClick={onCancel} disabled={busy}>{t('common.cancel')}</Button>
          <Button variant={danger ? 'danger' : 'primary'} onClick={confirm} loading={busy} disabled={askReason && reason.trim().length < 3}>
            {confirmLabel ?? t('common.confirm')}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
