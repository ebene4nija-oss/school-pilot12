/**
 * Pulls what the operator set in the console (Super Admin > Website) into
 * this build: contacts, the WhatsApp number, trust and legal switches, and
 * the product screenshots. Runs before every build, so a console change
 * reaches the site on its next rebuild.
 *
 *   src/generated/site-config.json   read by src/lib/site.ts
 *   src/generated/blog.json          published posts, read by src/lib/blog.ts
 *   src/generated/comparison.json    the compare table, read by src/lib/comparison.ts
 *   public/screens/<slot>.webp       read by ProductShot
 *   public/og-default.jpg            the default share preview (1200 x 630)
 *   public/blog/<slug>.jpg           each post's cover, also its share preview
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
const blogDir = path.join(root, "public/blog");
const ogFile = path.join(root, "public/og-default.jpg");
const generatedDir = path.join(root, "src/generated");

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
  await mkdir(path.dirname(configFile), { recursive: true });
  if (!existsSync(configFile)) await writeFile(configFile, "{}\n");
  const blogFile = path.join(path.dirname(configFile), "blog.json");
  const comparisonFile = path.join(path.dirname(configFile), "comparison.json");
  if (!existsSync(blogFile)) await writeFile(blogFile, "[]\n");
  if (!existsSync(comparisonFile)) await writeFile(comparisonFile, "null\n");
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

// Share previews are exactly 1200 x 630 (cropped to fit) and JPEG, which
// WhatsApp, Facebook, X and LinkedIn all read reliably.
async function writePreview(url, file) {
  const res = await fetch(url, { signal: AbortSignal.timeout(30_000) });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  await sharp(Buffer.from(await res.arrayBuffer()))
    .resize({ width: 1200, height: 630, fit: "cover", position: "centre" })
    .jpeg({ quality: 84, mozjpeg: true })
    .toFile(file);
}

let ogImage = null;
if (screens["social-preview"]) {
  try {
    await writePreview(screens["social-preview"], ogFile);
    ogImage = "/og-default.jpg";
  } catch (err) {
    if (required) {
      console.error(`site-config: share preview failed (${err.message})`);
      process.exit(1);
    }
    console.warn(`site-config: skipped share preview (${err.message})`);
  }
}
if (!ogImage && existsSync(ogFile)) await rm(ogFile);

for (const [slot, url] of Object.entries(screens)) {
  if (!url || slot === "social-preview") continue;
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
const settings = { ...config, og_image: ogImage };
delete settings.screens;
await mkdir(generatedDir, { recursive: true });
await writeFile(configFile, JSON.stringify(settings, null, 2) + "\n");

// Blog and comparison. A failure here never blocks the rest of the site: the
// build keeps whatever it fetched last, or an empty blog and no compare page.
async function getJson(endpoint) {
  const res = await fetch(`${apiBase}${endpoint}`, { headers: { Accept: "application/json" }, signal: AbortSignal.timeout(15_000) });
  if (!res.ok) throw new Error(`${endpoint} answered ${res.status}`);
  return res.json();
}

let posts = 0;
try {
  const { data } = await getJson("/public/blog");
  await mkdir(blogDir, { recursive: true });
  const keep = new Set();
  const out = [];
  for (const post of data) {
    let cover = null;
    if (post.cover_url) {
      try {
        await writePreview(post.cover_url, path.join(blogDir, `${post.slug}.jpg`));
        cover = `/blog/${post.slug}.jpg`;
        keep.add(`${post.slug}.jpg`);
      } catch (err) {
        console.warn(`site-config: skipped cover for ${post.slug} (${err.message})`);
      }
    }
    out.push({ ...post, cover_url: undefined, cover });
  }
  for (const file of await readdir(blogDir)) if (!keep.has(file)) await rm(path.join(blogDir, file));
  await writeFile(path.join(generatedDir, "blog.json"), JSON.stringify(out, null, 2) + "\n");
  posts = out.length;
} catch (err) {
  if (required) {
    console.error(`site-config: blog failed (${err.message})`);
    process.exit(1);
  }
  console.warn(`site-config: blog not refreshed (${err.message})`);
  if (!existsSync(path.join(generatedDir, "blog.json"))) await writeFile(path.join(generatedDir, "blog.json"), "[]\n");
}

try {
  const { data } = await getJson("/public/comparison");
  await writeFile(path.join(generatedDir, "comparison.json"), JSON.stringify(data, null, 2) + "\n");
} catch (err) {
  if (required) {
    console.error(`site-config: comparison failed (${err.message})`);
    process.exit(1);
  }
  console.warn(`site-config: comparison not refreshed (${err.message})`);
  if (!existsSync(path.join(generatedDir, "comparison.json"))) await writeFile(path.join(generatedDir, "comparison.json"), "null\n");
}

console.log(`site-config: fetched from ${apiBase}; ${written.size} screenshot(s), ${posts} post(s)${ogImage ? ", share preview" : ""}.`);
