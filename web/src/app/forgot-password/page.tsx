'use client';

import Link from 'next/link';
import { useActionState } from 'react';
import { requestPasswordReset, type ForgotPasswordState } from '@/app/actions/auth';

const initialState: ForgotPasswordState = {};

/**
 * Request a reset link.
 *
 * The confirmation never says whether the address was found — the API refuses
 * to, so that this page cannot be used to discover who holds an account at a
 * given school.
 */
export default function ForgotPasswordPage() {
  const [state, formAction, pending] = useActionState(requestPasswordReset, initialState);

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <div className="w-full max-w-sm rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        {state.sent ? (
          <>
            <h1 className="text-xl font-semibold text-slate-900">Check your email</h1>
            <p className="mt-2 text-sm text-slate-600">
              If that address has an account, a link to set a new password is on its way to it.
              The link expires in an hour.
            </p>
            <Link
              href="/login"
              className="mt-6 block rounded-lg bg-slate-900 px-4 py-2.5 text-center text-sm font-medium text-white transition hover:bg-slate-800"
            >
              Back to sign in
            </Link>
          </>
        ) : (
          <>
            <h1 className="text-xl font-semibold text-slate-900">Forgot your password?</h1>
            <p className="mt-1 text-sm text-slate-500">
              Enter the email address your school has on file and we will send you a link to set a
              new one.
            </p>

            <form action={formAction} className="mt-6 space-y-4">
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

              {state.error && (
                <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                  {state.error}
                </p>
              )}

              <button
                type="submit"
                disabled={pending}
                className="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-60"
              >
                {pending ? 'Sending…' : 'Send reset link'}
              </button>
            </form>

            <Link
              href="/login"
              className="mt-4 block text-center text-sm text-slate-500 hover:text-slate-900"
            >
              Back to sign in
            </Link>
          </>
        )}
      </div>
    </main>
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
      <label htmlFor={name} className="block text-sm font-medium text-slate-700">
        {label}
      </label>
      <input
        id={name}
        name={name}
        required
        aria-invalid={errors ? true : undefined}
        aria-describedby={errors ? `${name}-error` : undefined}
        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-slate-900 focus:ring-1 focus:ring-slate-900"
        {...props}
      />
      {errors && (
        <p id={`${name}-error`} className="mt-1 text-xs text-red-600">
          {errors[0]}
        </p>
      )}
    </div>
  );
}
