'use server';

import { redirect } from 'next/navigation';
import { z } from 'zod';
import { createSession, destroySession, getSession, type SessionRole } from '@/lib/session';

/**
 * Login and logout, as Server Actions.
 *
 * Credentials never touch client JavaScript and the token never leaves the
 * server: the action calls Laravel, then writes the token into an httpOnly
 * cookie. The web app previously had no login at all — no form, no token, no
 * `Authorization` header anywhere — so every page 401'd in a browser and
 * swallowed the error into `console.error`.
 */

const API_BASE_URL =
  process.env.BACKEND_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1';

const LoginSchema = z.object({
  email: z.string().email('Enter a valid email address.'),
  password: z.string().min(1, 'Enter your password.'),
  subdomain: z.string().trim().min(1, 'Enter your school code.'),
  two_factor_code: z.string().optional(),
});

export interface LoginState {
  error?: string;
  fieldErrors?: Record<string, string[]>;
  requiresTwoFactor?: boolean;
}

export async function login(_prev: LoginState, formData: FormData): Promise<LoginState> {
  const parsed = LoginSchema.safeParse({
    email: formData.get('email'),
    password: formData.get('password'),
    subdomain: formData.get('subdomain'),
    two_factor_code: formData.get('two_factor_code') || undefined,
  });

  if (!parsed.success) {
    // zod v3 in this project — `flatten()`, not the v4 `z.flattenError`.
    return { fieldErrors: parsed.error.flatten().fieldErrors };
  }

  const { subdomain, ...credentials } = parsed.data;

  let response: Response;

  try {
    response = await fetch(`${API_BASE_URL}/auth/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        // Server-side fetch, so this is a real header the API will see —
        // unlike the `Host` header the old client code tried to set.
        'X-School-Subdomain': subdomain,
      },
      body: JSON.stringify({ ...credentials, subdomain }),
      cache: 'no-store',
    });
  } catch {
    return { error: 'Could not reach the school server. Check your connection and try again.' };
  }

  const payload = await response.json().catch(() => ({}));

  if (response.status === 429) {
    return { error: 'Too many attempts. Please wait a minute and try again.' };
  }

  if (!response.ok) {
    // The API signals a 2FA challenge rather than a bad password; surface the
    // code field instead of telling the user their credentials were wrong.
    if (payload?.requires_two_factor || payload?.two_factor_required) {
      return { requiresTwoFactor: true };
    }

    return { error: payload?.message ?? payload?.error ?? 'Those details were not recognised.' };
  }

  const token: string | undefined = payload?.token ?? payload?.access_token;

  if (!token) {
    return { error: 'The server did not return a session token. Please contact support.' };
  }

  const role: SessionRole | null = payload?.user?.user_profile?.role ?? payload?.role ?? null;

  await createSession(token, role, subdomain);

  redirect('/');
}

export async function logout(): Promise<void> {
  const session = await getSession();

  // Revoke the token server-side before dropping the cookie. Deleting the
  // cookie alone left a working Sanctum token in existence with nothing able to
  // reach it — on a shared machine that is a live session, not a signed-out one.
  if (session) {
    try {
      await fetch(`${API_BASE_URL}/auth/logout`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${session.token}`,
          ...(session.subdomain ? { 'X-School-Subdomain': session.subdomain } : {}),
        },
        cache: 'no-store',
      });
    } catch {
      // An unreachable API must not trap the user in a signed-in shell. The
      // cookie goes either way.
    }
  }

  await destroySession();
  redirect('/login');
}

const ForgotPasswordSchema = z.object({
  email: z.string().email('Enter a valid email address.'),
  subdomain: z.string().trim().min(1, 'Enter your school code.'),
});

export interface ForgotPasswordState {
  error?: string;
  fieldErrors?: Record<string, string[]>;
  sent?: boolean;
}

/**
 * Request a reset link.
 *
 * The success state is set for any accepted request, because the API answers
 * identically whether or not the address is registered. Reporting "no such
 * account" here would give away what the API deliberately withholds.
 */
export async function requestPasswordReset(
  _prev: ForgotPasswordState,
  formData: FormData,
): Promise<ForgotPasswordState> {
  const parsed = ForgotPasswordSchema.safeParse({
    email: formData.get('email'),
    subdomain: formData.get('subdomain'),
  });

  if (!parsed.success) {
    return { fieldErrors: parsed.error.flatten().fieldErrors };
  }

  const { email, subdomain } = parsed.data;

  let response: Response;

  try {
    response = await fetch(`${API_BASE_URL}/auth/forgot-password`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-School-Subdomain': subdomain,
      },
      body: JSON.stringify({ email, subdomain }),
      cache: 'no-store',
    });
  } catch {
    return { error: 'Could not reach the school server. Check your connection and try again.' };
  }

  if (response.status === 429) {
    return { error: 'Too many requests. Please wait a minute and try again.' };
  }

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    return { error: payload?.message ?? 'That request could not be completed.' };
  }

  return { sent: true };
}

const ResetPasswordSchema = z
  .object({
    email: z.string().email('Enter a valid email address.'),
    token: z.string().min(1, 'This link is missing its token. Request a new one.'),
    password: z.string().min(8, 'Use at least 8 characters, with letters and numbers.'),
    password_confirmation: z.string().min(1, 'Confirm your new password.'),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Both passwords must match.',
    path: ['password_confirmation'],
  });

export interface ResetPasswordState {
  error?: string;
  fieldErrors?: Record<string, string[]>;
  done?: boolean;
}

export async function resetPassword(
  _prev: ResetPasswordState,
  formData: FormData,
): Promise<ResetPasswordState> {
  const parsed = ResetPasswordSchema.safeParse({
    email: formData.get('email'),
    token: formData.get('token'),
    password: formData.get('password'),
    password_confirmation: formData.get('password_confirmation'),
  });

  if (!parsed.success) {
    return { fieldErrors: parsed.error.flatten().fieldErrors };
  }

  let response: Response;

  try {
    response = await fetch(`${API_BASE_URL}/auth/reset-password`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(parsed.data),
      cache: 'no-store',
    });
  } catch {
    return { error: 'Could not reach the school server. Check your connection and try again.' };
  }

  const payload = await response.json().catch(() => ({}));

  if (response.status === 429) {
    return { error: 'Too many attempts. Please wait a minute and try again.' };
  }

  if (!response.ok) {
    if (payload?.errors) {
      return { fieldErrors: payload.errors };
    }

    return { error: payload?.message ?? 'That reset link is invalid or has expired.' };
  }

  return { done: true };
}

/** For server components deciding what to render. */
export async function currentSession() {
  return getSession();
}
