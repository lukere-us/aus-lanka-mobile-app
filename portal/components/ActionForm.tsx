'use client';

import { useActionState } from 'react';
import type { ActionResult } from '@/lib/actions';

type Action = (prev: ActionResult, form: FormData) => Promise<ActionResult>;

/**
 * A form wired to a server action, showing the API's answer (success or error) above the fields.
 * `confirm` asks before sending, for actions that are hard to undo.
 */
export function ActionForm({
  action,
  children,
  submitLabel,
  pendingLabel = 'Saving…',
  confirm,
  className,
  variant = 'primary',
  inline = false,
}: {
  action: Action;
  children?: React.ReactNode;
  submitLabel: string;
  pendingLabel?: string;
  confirm?: string;
  className?: string;
  variant?: 'primary' | 'default' | 'danger';
  inline?: boolean;
}) {
  const [state, formAction, pending] = useActionState<ActionResult, FormData>(action, {});

  return (
    <form
      action={formAction}
      className={(inline ? 'form-inline ' : 'form ') + (className ?? '')}
      onSubmit={(e: React.FormEvent<HTMLFormElement>) => {
        if (confirm && !window.confirm(confirm)) e.preventDefault();
      }}
    >
      {state.error && (
        <div className="alert alert-error" role="alert">
          {state.error}
          {state.details && Object.keys(state.details).length > 0 && (
            <ul>
              {Object.entries(state.details).map(([field, messages]) => (
                <li key={field}>{messages.join(' ')}</li>
              ))}
            </ul>
          )}
        </div>
      )}
      {state.ok && !state.error && (
        <div className="alert alert-success" role="status">
          {state.ok}
        </div>
      )}
      {children}
      <div className="form-actions">
        <button
          type="submit"
          className={'btn btn-sm' + (variant === 'primary' ? ' btn-primary' : variant === 'danger' ? ' btn-danger' : '')}
          disabled={pending}
        >
          {pending ? pendingLabel : submitLabel}
        </button>
      </div>
    </form>
  );
}
