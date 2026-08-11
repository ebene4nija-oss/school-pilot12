'use client';

import Link from 'next/link';
import { use, useActionState } from 'react';
import { resetPassword, type ResetPasswordState } from '@/app/actions/auth';

const initialState: ResetPasswordState = {};

/**
 * Where a reset or account-setup link lands.
 *
 * `searchParams` is a promise in this version of Next, so it is read with
 * React's `use()` — this page is a Client Component and cannot be async.
 *
 * The token and address are carried in hidden fields rather than re-read from
 * the URL at submit time. They are already public: they arrived in the query
 * string of a link the user clicked, and the token is single-use and expiring.
 */
export default function ResetPasswordPage({
  searchParams,
}: {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
}) {
  const params = use(searchParams);
  const [state, formAction, pending] = useActionState(resetPassword, initialState);

  const token = typeof params.token === 'string' ? params.token : '';
  const email = typeof params.email === 'string' ? params.email : '';

  if (!token || !email) {
    return (
      <Shell title="This link is incomplete">
        <p className="mt-2 text-sm text-slate-600">
          Open the link exactly as it was sent to you — some mail apps cut long links in half. If
          that does not work, request a new one.
        </p>
        <Link
          href="/forgot-password"
          className="mt-6 block rounded-lg bg-slate-900 px-4 py-2.5 text-center text-sm font-medium text-white transition hover:bg-slate-800"
        >
          Request a new link
        </Link>
      </Shell>
    );
  }

  if (state.done) {
    return (
      <Shell title="Password updated">
        <p className="mt-2 text-sm text-slate-600">
          You can now sign in with your new password. Any other devices you were signed in on have
          been signed out.
        </p>
        <Link
          href="/login"
          className="mt-6 block rounded-lg bg-slate-900 px-4 py-2.5 text-center text-sm font-medium text-white transition hover:bg-slate-800"
        >
          Sign in
        </Link>
      </Shell>
    );
  }

  return (
    <Shell title="Set a new password">
      <p className="mt-1 text-sm text-slate-500">
        For <span className="font-medium text-slate-700">{email}</span>
      </p>

      <form action={formAction} className="mt-6 space-y-4">
        <input type="hidden" name="token" value={token} />
        <input type="hidden" name="email" value={email} />

        <Field
          label="New password"
          name="password"
          type="password"
          autoComplete="new-password"
          errors={state.fieldErrors?.password}
        />

        <Field
          label="Confirm new password"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          errors={state.fieldErrors?.password_confirmation}
        />

        <p className="text-xs text-slate-500">
          At least 8 characters, including letters and numbers.
        </p>

        {state.error && (
          <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
            {state.error}{' '}
            <Link href="/forgot-password" className="font-medium underline">
              Request a new link
            </Link>
          </p>
        )}

        <button
          type="submit"
          disabled={pending}
          className="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-60"
        >
          {pending ? 'Saving…' : 'Save new password'}
        </button>
      </form>
    </Shell>
  );
}

function Shell({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <div className="w-full max-w-sm rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
        {children}
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
