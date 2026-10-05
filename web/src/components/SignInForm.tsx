'use client';

import Link from 'next/link';
import { useActionState } from 'react';
import { login, type LoginState } from '@/app/actions/auth';

const initialState: LoginState = {};

/**
 * The sign-in form, shared by `/login` and the school's public home.
 *
 * Credentials are submitted to a Server Action, so they never pass through
 * client-side state and the returned token never becomes readable by the page.
 * One form for every role: who someone is decides where they land, after
 * sign-in, not which form they picked.
 */
export function SignInForm({ accent }: { accent?: string }) {
  const [state, formAction, pending] = useActionState(login, initialState);

  return (
    <form action={formAction} className="space-y-4">
      <Field
        label="School code"
        name="subdomain"
        placeholder="greenfield"
        autoComplete="organization"
        errors={state.fieldErrors?.subdomain}
      />

      <Field
        label="Email"
        name="email"
        type="email"
        autoComplete="username"
        errors={state.fieldErrors?.email}
      />

      <Field
        label="Password"
        name="password"
        type="password"
        autoComplete="current-password"
        errors={state.fieldErrors?.password}
      />

      <div className="text-right">
        <Link href="/forgot-password" className="text-sm text-ink-muted hover:text-brand">
          Forgot password?
        </Link>
      </div>

      {state.requiresTwoFactor && (
        <Field
          label="Authentication code"
          name="two_factor_code"
          inputMode="numeric"
          autoComplete="one-time-code"
          placeholder="6-digit code"
        />
      )}

      {state.error && (
        <p role="alert" className="rounded-lg bg-negative-soft px-3 py-2 text-sm text-negative">
          {state.error}
        </p>
      )}

      <button
        type="submit"
        disabled={pending}
        style={accent ? { backgroundColor: accent } : undefined}
        className={`w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-medium text-white transition disabled:opacity-60 ${accent ? 'hover:opacity-90' : 'hover:bg-brand-soft'}`}
      >
        {pending ? 'Signing in…' : 'Sign in'}
      </button>
    </form>
  );
}

function Field({
  label,
  name,
  errors,
  ...props
}: {
  label: string;
  name: string;
  errors?: string[];
} & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <div>
      <label htmlFor={name} className="block text-sm font-medium text-ink">
        {label}
      </label>
      <input
        id={name}
        name={name}
        required
        aria-invalid={errors ? true : undefined}
        aria-describedby={errors ? `${name}-error` : undefined}
        className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm text-brand outline-none focus:border-brand focus:ring-1 focus:ring-brand/20"
        {...props}
      />
      {errors && (
        <p id={`${name}-error`} className="mt-1 text-xs text-negative">
          {errors[0]}
        </p>
      )}
    </div>
  );
}
