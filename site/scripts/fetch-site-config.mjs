/**
 * Pulls what the operator set in the console (Super Admin > Website) into
 * this build: contacts, the WhatsApp number, trust and legal switches, and
 * the product screenshots. Runs before every build, so a console change
 * reaches the site on its next rebuild.
 *
 *   src/generated/site-config.json   read by src/lib/site.ts
 *   public/screens/<slot>.webp       read by ProductShot
 *
 * Screenshots are resized and converted to WebP here, so whatever size the
 * operator uploaded, the site ships a light image.
 *
 * If the API can't be reached, the build keeps the last config it fetched
 * (or none) and says so. Set SITE_CONFIG_REQUIRED=1 on the production build
 * so it fails instead of shipping without the console's values.
 */
import { mkdir, readdir, rm, writeFile } from "node:fs/promises";
import { existsSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import sharp from "sharp";

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, "..");
const configFile = path.join(root, "src/generated/site-config.json");
const screensDir = path.join(root, "public/screens");

const apiBase = (process.env.NEXT_PUBLIC_API_BASE ?? "http://localhost:8000/api/v1").replace(/\/$/, "");
const required = process.env.SITE_CONFIG_REQUIRED === "1";

// Width the site shows each frame at, written at that size.
const WIDTH = { desktop: 1280, phone: 390 };
const isPhone = (slot) => slot.endsWith("-phone");

async function fail(message) {
  if (required) {
    console.error(`site-config: ${message}`);
    process.exit(1);
  }
  console.warn(`site-config: ${message}. Building with ${existsSync(configFile) ? "the last fetched config" : "no console config"}.`);
  if (!existsSync(configFile)) {
    await mkdir(path.dirname(configFile), { recursive: true });
    await writeFile(configFile, "{}\n");
  }
  process.exit(0);
}

let config;
try {
  const res = await fetch(`${apiBase}/public/site-config`, {
    headers: { Accept: "application/json" },
    signal: AbortSignal.timeout(10_000),
  });
  if (!res.ok) await fail(`${apiBase}/public/site-config answered ${res.status}`);
  config = await res.json();
} catch (err) {
  await fail(`could not reach ${apiBase} (${err.message})`);
}

await mkdir(screensDir, { recursive: true });
const screens = config.screens ?? {};
const written = new Set();

for (const [slot, url] of Object.entries(screens)) {
  if (!url) continue;
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout(30_000) });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const input = Buffer.from(await res.arrayBuffer());
    await sharp(input)
      .resize({ width: WIDTH[isPhone(slot) ? "phone" : "desktop"], withoutEnlargement: true })
      .webp({ quality: 80 })
      .toFile(path.join(screensDir, `${slot}.webp`));
    written.add(`${slot}.webp`);
  } catch (err) {
    if (required) {
      console.error(`site-config: screenshot ${slot} failed (${err.message})`);
      process.exit(1);
    }
    console.warn(`site-config: skipped screenshot ${slot} (${err.message})`);
  }
}

// A slot emptied in the console goes back to its "to capture" frame.
for (const file of await readdir(screensDir)) {
  if (file.endsWith(".webp") && !written.has(file)) await rm(path.join(screensDir, file));
}

// The screenshots are files now; the config keeps only the settings.
const settings = { ...config };
delete settings.screens;
await mkdir(path.dirname(configFile), { recursive: true });
await writeFile(configFile, JSON.stringify(settings, null, 2) + "\n");

console.log(`site-config: fetched from ${apiBase}; ${written.size} screenshot(s).`);
