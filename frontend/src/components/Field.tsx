'use client';

import { useId, useState, type InputHTMLAttributes, type ReactNode, type TextareaHTMLAttributes } from 'react';
import { length } from '@/lib/format';
import { Icon } from './Icon';

interface FieldShellProps {
  id: string;
  label: ReactNode;
  error?: string;
  hint?: ReactNode;
  counter?: { value: string; max: number };
  aside?: ReactNode;
  children: ReactNode;
}

function FieldShell({ id, label, error, hint, counter, aside, children }: FieldShellProps) {
  return (
    <div className="field">
      <div className="field__label-row">
        <label className="field__label" htmlFor={id}>
          {label}
        </label>
        {counter ? (
          <span className="field__counter" aria-hidden="true">
            {length(counter.value)} / {counter.max}
          </span>
        ) : (
          aside
        )}
      </div>
      {children}
      {hint && !error ? (
        <span className="field__hint" id={`${id}-hint`}>
          {hint}
        </span>
      ) : null}
      {error ? (
        <span className="field__error" id={`${id}-error`} role="alert">
          {error}
        </span>
      ) : null}
    </div>
  );
}

type InputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> & {
  label: ReactNode;
  error?: string;
  hint?: ReactNode;
  counter?: boolean;
  aside?: ReactNode;
};

export function TextField({ label, error, hint, counter, aside, ...input }: InputProps) {
  const id = useId();
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;

  return (
    <FieldShell
      id={id}
      label={label}
      error={error}
      hint={hint}
      aside={aside}
      counter={counter && input.maxLength ? { value: String(input.value ?? ''), max: input.maxLength } : undefined}
    >
      <input id={id} className="input" aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...input} />
    </FieldShell>
  );
}

export function PasswordField({ label, error, hint, aside, ...input }: InputProps) {
  const id = useId();
  const [visible, setVisible] = useState(false);
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;

  return (
    <FieldShell id={id} label={label} error={error} hint={hint} aside={aside}>
      <div className="input-wrap">
        <input
          id={id}
          className="input"
          type={visible ? 'text' : 'password'}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          {...input}
        />
        <button type="button" className="input-wrap__btn" onClick={() => setVisible((value) => !value)} aria-label={visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}>
          <Icon name={visible ? 'eyeOff' : 'eye'} />
        </button>
      </div>
    </FieldShell>
  );
}

type TextAreaProps = Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'id'> & {
  label: ReactNode;
  error?: string;
  hint?: ReactNode;
  counter?: boolean;
};

export function TextAreaField({ label, error, hint, counter, ...textarea }: TextAreaProps) {
  const id = useId();
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;

  return (
    <FieldShell
      id={id}
      label={label}
      error={error}
      hint={hint}
      counter={counter && textarea.maxLength ? { value: String(textarea.value ?? ''), max: textarea.maxLength } : undefined}
    >
      <textarea id={id} className="textarea" aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...textarea} />
    </FieldShell>
  );
}

export function FormError({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <p className="alert alert--error" role="alert">
      {message}
    </p>
  );
}
