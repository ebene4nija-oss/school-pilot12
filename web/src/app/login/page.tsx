'use client';

import Image from 'next/image';
import Link from 'next/link';
import { useActionState } from 'react';
import { login, type LoginState } from '@/app/actions/auth';

const initialState: LoginState = {};

/**
 * The sign-in screen the admin portal did not have.
 *
 * Credentials are submitted to a Server Action, so they never pass through
 * client-side state and the returned token never becomes readable by the page.
 */
export default function LoginPage() {
  const [state, formAction, pending] = useActionState(login, initialState);

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <div className="w-full max-w-sm rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        <Image
          src="/brand/logo.png"
          alt="SchoolPilot"
          width={1024}
          height={210}
          className="mb-6 h-8 w-auto"
          priority
        />
        <h1 className="text-xl font-semibold text-slate-900">Sign in to SchoolPilot</h1>
        <p className="mt-1 text-sm text-slate-500">Use the details your school gave you.</p>

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

          <Field
            label="Password"
            name="password"
            type="password"
            autoComplete="current-password"
            errors={state.fieldErrors?.password}
          />

          <div className="text-right">
            <Link
              href="/forgot-password"
              className="text-sm text-slate-500 hover:text-slate-900"
            >
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
            <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
              {state.error}
            </p>
          )}

          <button
            type="submit"
            disabled={pending}
            className="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-60"
          >
            {pending ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
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
