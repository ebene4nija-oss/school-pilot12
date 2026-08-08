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
  await destroySession();
  redirect('/login');
}

/** For server components deciding what to render. */
export async function currentSession() {
  return getSession();
}
