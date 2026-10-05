'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { refreshPublicSchoolProfile } from '@/app/actions/school-profile';
import { Badge, Button, Card, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * School profile: what the school's own address shows a visitor who is not
 * signed in — its crest, name, motto and hero beside the sign-in form, and,
 * once published, an about section, contact details and admissions.
 *
 * Everything entered here is public. That is why it asks for the school
 * office's numbers rather than anyone's own, and why the hero photo needs a
 * confirmation about pupils before it can be uploaded.
 */

type Network = 'facebook' | 'instagram' | 'x' | 'youtube' | 'linkedin' | 'tiktok';

interface Profile {
  name: string;
  subdomain: string;
  short_name: string | null;
  motto: string | null;
  about: string | null;
  address: string | null;
  state: string | null;
  lga: string | null;
  phone: string | null;
  email: string | null;
  website: string | null;
  social_links: Partial<Record<Network, string>>;
  school_type: string | null;
  brand_color: string | null;
  logo_url: string | null;
  hero_image_url: string | null;
  published: boolean;
  published_at: string | null;
}

interface ProfileResponse {
  message?: string;
  profile: Profile;
  public_url: string;
  sections: string[];
  options: { states: string[]; school_types: Record<string, string>; social_networks: Network[] };
}

const TEXT_FIELDS = ['short_name', 'motto', 'about', 'address', 'state', 'lga', 'phone', 'email', 'website', 'school_type', 'brand_color'] as const;
type TextField = (typeof TEXT_FIELDS)[number];
type Draft = Record<TextField, string> & { social_links: Partial<Record<Network, string>> };

const NETWORK_LABELS: Record<Network, string> = {
  facebook: 'Facebook',
  instagram: 'Instagram',
  x: 'X (Twitter)',
  youtube: 'YouTube',
  linkedin: 'LinkedIn',
  tiktok: 'TikTok',
};

const DEFAULT_ACCENT = '#002447';

function draftOf(p: Profile): Draft {
  const d = Object.fromEntries(TEXT_FIELDS.map((f) => [f, (p[f] as string | null) ?? ''])) as Record<TextField, string>;
  return { ...d, social_links: { ...p.social_links } };
}

/** WCAG contrast of white text on the colour — mirrors the API's check. */
function contrastWithWhite(hex: string): number {
  const h = hex.replace('#', '');
  const ch = (i: number) => {
    const c = parseInt(h.slice(i, i + 2), 16) / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  const l = 0.2126 * ch(0) + 0.7152 * ch(2) + 0.0722 * ch(4);
  return 1.05 / (l + 0.05);
}

export default function SchoolProfilePage() {
  const [data, setData] = useState<ProfileResponse | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    fetchApi<ProfileResponse>('/school/profile')
      .then((res) => {
        setData(res);
        setDraft(draftOf(res.profile));
      })
      .catch((err) => setError(errorText(err, 'Could not load the school profile.')));
  }, []);

  /** An image change keeps unsaved text edits; a save replaces them with what was stored. */
  const apply = async (res: ProfileResponse, message: string, keepDraft = false) => {
    setData(res);
    if (!keepDraft) setDraft(draftOf(res.profile));
    setNotice(message);
    setError(null);
    await refreshPublicSchoolProfile();
  };

  const save = async (published?: boolean) => {
    if (!draft) return;
    setSaving(true);
    setNotice(null);

    try {
      const body: Record<string, unknown> = Object.fromEntries(TEXT_FIELDS.map((f) => [f, draft[f].trim() || null]));
      body.social_links = draft.social_links;
      if (published !== undefined) body.published = published;

      const res = await fetchApi<ProfileResponse>('/school/profile', { method: 'PUT', body: JSON.stringify(body) });
      await apply(res, published === true ? 'Published. Your school page is now public.' : published === false ? 'Unpublished. Search engines are asked to stay away.' : 'Saved.');
    } catch (err) {
      setError(errorText(err, 'Could not save the school profile.'));
    } finally {
      setSaving(false);
    }
  };

  if (!data || !draft) {
    return (
      <PageShell title="School profile">
        <ErrorNote message={error} />
        {!error && <Loading />}
      </PageShell>
    );
  }

  const { profile, options } = data;
  const set = (field: TextField) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
    setDraft({ ...draft, [field]: e.target.value });
  const accent = /^#[0-9a-f]{6}$/i.test(draft.brand_color) ? draft.brand_color : DEFAULT_ACCENT;
  const colourTooLight = /^#[0-9a-f]{6}$/i.test(draft.brand_color) && contrastWithWhite(draft.brand_color) < 4.5;

  return (
    <PageShell
      title="School profile"
      subtitle="What your school's address shows visitors who are not signed in."
      actions={
        <div className="flex flex-wrap items-center gap-2">
          {profile.published ? <Badge tone="green">Published</Badge> : <Badge>Not published</Badge>}
          <a href="/school-home" target="_blank" rel="noopener" className="rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink hover:bg-surface-sunken">
            View page
          </a>
        </div>
      }
    >
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-6 rounded-lg border border-positive/20 bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="space-y-6">
          <Card title="Identity" description={`Shown on your sign-in page at ${data.public_url.replace(/^https?:\/\//, '').replace(/\/$/, '')}, whether or not the profile is published.`}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="School name">
                <input className={`${inputClass} bg-surface-sunken`} value={profile.name} disabled />
                <span className="mt-1 block text-xs text-ink-faint">Printed on report cards. Ask SchoolPilot to change it.</span>
              </Field>
              <Field label="Short name">
                <input className={inputClass} value={draft.short_name} onChange={set('short_name')} maxLength={40} placeholder="Greenfield" />
              </Field>
              <div className="sm:col-span-2">
                <Field label="Motto">
                  <input className={inputClass} value={draft.motto} onChange={set('motto')} maxLength={150} placeholder="Excellence in Learning, Character & Leadership" />
                </Field>
              </div>
              <Field label="Brand colour">
                <div className="flex gap-2">
                  <input
                    type="color"
                    aria-label="Pick brand colour"
                    className="h-9 w-12 cursor-pointer rounded border border-line"
                    value={accent}
                    onChange={set('brand_color')}
                  />
                  <input className={inputClass} value={draft.brand_color} onChange={set('brand_color')} placeholder="#0b5d3b" maxLength={7} />
                </div>
                {colourTooLight && (
                  <span className="mt-1 block text-xs text-negative">Too light for white text on buttons. Choose a darker shade.</span>
                )}
              </Field>
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-2">
              <ImageSlot
                kind="logo"
                label="Crest / logo"
                hint="PNG, JPG or WebP, square, at least 128 px. Up to 1 MB."
                url={profile.logo_url}
                onDone={(res, msg) => apply(res, msg, true)}
                onError={setError}
              />
              <ImageSlot
                kind="hero"
                label="Hero photo"
                hint="Your building, gate or grounds. At least 1200 px wide, up to 5 MB."
                url={profile.hero_image_url}
                onDone={(res, msg) => apply(res, msg, true)}
                onError={setError}
              />
            </div>
          </Card>

          <Card title="About" description="Shown once the profile is published.">
            <div className="space-y-4">
              <Field label="About the school">
                <textarea className={`${inputClass} min-h-32`} value={draft.about} onChange={set('about')} maxLength={2000} placeholder="Who you are, what you offer, what makes the school distinct." />
                <span className="mt-1 block text-right text-xs text-ink-faint">{draft.about.length} / 2000</span>
              </Field>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Day or boarding">
                  <select className={inputClass} value={draft.school_type} onChange={set('school_type')}>
                    <option value="">Not stated</option>
                    {Object.entries(options.school_types).map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </select>
                </Field>
                <div>
                  <span className="mb-1 block text-sm font-medium text-ink">Sections</span>
                  <p className="text-sm text-ink-muted">
                    {data.sections.length ? data.sections.join(' · ') : 'None yet'}
                  </p>
                  <span className="mt-1 block text-xs text-ink-faint">From the classes you have set up.</span>
                </div>
              </div>
            </div>
          </Card>

          <Card title="Contact" description="The school office's details. Don't use a staff member's personal number or email: this page is public and indexed.">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Field label="Address">
                  <input className={inputClass} value={draft.address} onChange={set('address')} maxLength={255} placeholder="12 Allen Avenue" />
                </Field>
              </div>
              <Field label="State">
                <select className={inputClass} value={draft.state} onChange={set('state')}>
                  <option value="">Choose…</option>
                  {options.states.map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="LGA">
                <input className={inputClass} value={draft.lga} onChange={set('lga')} maxLength={80} placeholder="Ikeja" />
              </Field>
              <Field label="School office phone">
                <input className={inputClass} type="tel" value={draft.phone} onChange={set('phone')} maxLength={24} placeholder="0803 123 4567" />
              </Field>
              <Field label="School email">
                <input className={inputClass} type="email" value={draft.email} onChange={set('email')} maxLength={150} placeholder="info@yourschool.com" />
              </Field>
              <div className="sm:col-span-2">
                <Field label="Website">
                  <input className={inputClass} type="url" value={draft.website} onChange={set('website')} maxLength={255} placeholder="https://" />
                </Field>
              </div>
            </div>

            <h3 className="mt-6 text-sm font-medium text-ink">Social media</h3>
            <div className="mt-2 grid gap-3 sm:grid-cols-2">
              {options.social_networks.map((network) => (
                <Field key={network} label={NETWORK_LABELS[network] ?? network}>
                  <input
                    className={inputClass}
                    type="url"
                    placeholder="https://"
                    value={draft.social_links[network] ?? ''}
                    onChange={(e) => setDraft({ ...draft, social_links: { ...draft.social_links, [network]: e.target.value } })}
                  />
                </Field>
              ))}
            </div>
          </Card>
        </div>

        <aside className="space-y-6 xl:sticky xl:top-6 xl:self-start">
          <Card title="Preview">
            <div className="overflow-hidden rounded-lg ring-1 ring-line-soft">
              <div className="h-28 bg-surface-raised" style={profile.hero_image_url ? undefined : { backgroundColor: accent }}>
                {profile.hero_image_url && (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={profile.hero_image_url} alt="" className="h-full w-full object-cover" />
                )}
              </div>
              <div className="p-4">
                <p className="text-xs text-ink-muted">Welcome to</p>
                <p className="font-semibold leading-tight" style={{ color: accent }}>
                  {profile.name}
                </p>
                {draft.motto && <p className="mt-0.5 text-xs italic text-ink-muted">{draft.motto}</p>}
                <div className="mt-3 rounded-md py-1.5 text-center text-xs font-medium text-white" style={{ backgroundColor: accent }}>
                  Sign in
                </div>
              </div>
            </div>
          </Card>

          <Card
            title="Publishing"
            description={
              profile.published
                ? 'Your page is public and search engines may list it, so parents searching for the school can find it.'
                : 'Until you publish, your address shows only the branded sign-in, and search engines are asked not to list it.'
            }
          >
            <ul className="mb-4 space-y-1 text-sm text-ink-muted">
              <li>Never shown: students, parents, staff, results, attendance or notices.</li>
              <li>Needs: about, address, state, and a phone or email.</li>
            </ul>
            <div className="flex flex-wrap gap-2">
              <Button onClick={() => save()} disabled={saving}>
                {saving ? 'Saving…' : 'Save'}
              </Button>
              {profile.published ? (
                <Button variant="secondary" onClick={() => save(false)} disabled={saving}>
                  Unpublish
                </Button>
              ) : (
                <Button variant="accent" onClick={() => save(true)} disabled={saving}>
                  Save & publish
                </Button>
              )}
            </div>
          </Card>
        </aside>
      </div>
    </PageShell>
  );
}

function ImageSlot({
  kind,
  label,
  hint,
  url,
  onDone,
  onError,
}: {
  kind: 'logo' | 'hero';
  label: string;
  hint: string;
  url: string | null;
  onDone: (res: ProfileResponse, message: string) => void;
  onError: (message: string) => void;
}) {
  const [file, setFile] = useState<File | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const inputId = `image-${kind}`;

  const upload = async () => {
    if (!file) return;
    setBusy(true);

    try {
      const form = new FormData();
      form.append('image', file);
      if (kind === 'hero') form.append('no_identifiable_pupils', confirmed ? '1' : '0');

      const res = await uploadApi<ProfileResponse>(`/school/profile/images/${kind}`, form);
      setFile(null);
      setConfirmed(false);
      onDone(res, res.message ?? 'Saved.');
    } catch (err) {
      onError(errorText(err, 'Could not upload that image.'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    setBusy(true);

    try {
      const res = await fetchApi<ProfileResponse>(`/school/profile/images/${kind}`, { method: 'DELETE' });
      onDone(res, 'Image removed.');
    } catch (err) {
      onError(errorText(err, 'Could not remove that image.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="rounded-lg border border-line-soft p-3">
      <label htmlFor={inputId} className="block text-sm font-medium text-ink">
        {label}
      </label>
      <p className="text-xs text-ink-faint">{hint}</p>

      <div className={`mt-3 flex items-center justify-center overflow-hidden rounded-md bg-surface-sunken ${kind === 'hero' ? 'h-28' : 'h-20'}`}>
        {url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={url} alt="" className={kind === 'hero' ? 'h-full w-full object-cover' : 'h-16 w-16 object-contain'} />
        ) : (
          <span className="text-xs text-ink-faint">None yet</span>
        )}
      </div>

      <input
        id={inputId}
        type="file"
        accept="image/png,image/jpeg,image/webp"
        className="mt-3 block w-full text-xs text-ink-muted file:mr-2 file:rounded file:border-0 file:bg-surface-raised file:px-2 file:py-1 file:text-ink"
        onChange={(e) => setFile(e.target.files?.[0] ?? null)}
      />

      {kind === 'hero' && file && (
        <label className="mt-3 flex gap-2 text-xs text-ink">
          <input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} className="mt-0.5" />
          <span>
            This photo shows no recognisable pupils, or we hold their parents&apos; written consent to publish it. It
            will be on a public page that search engines can list.
          </span>
        </label>
      )}

      <div className="mt-3 flex gap-2">
        <Button type="button" variant="secondary" onClick={upload} disabled={!file || busy || (kind === 'hero' && !confirmed)}>
          {busy ? 'Working…' : 'Upload'}
        </Button>
        {url && (
          <Button type="button" variant="secondary" onClick={remove} disabled={busy}>
            Remove
          </Button>
        )}
      </div>
    </div>
  );
}
