/**
 * Brand assets come from the product, not from a copy kept by hand
 * (Landing PRD §14.1). This reads web/public/brand and writes small WebP
 * versions sized for the site's nav and footer into public/brand.
 *
 * The source PNGs are 50–130 KB each; at the size the site shows them they
 * need a few KB, and the homepage budget is 800 KB in total.
 */
import { mkdir, readdir } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import sharp from "sharp";

const here = path.dirname(fileURLToPath(import.meta.url));
const source = path.resolve(here, "../../web/public/brand");
const target = path.resolve(here, "../public/brand");

// Height in CSS pixels shown on the site; written at 2x for sharp screens.
const HEIGHTS = { logo: 36, "logo-white": 36, mark: 64, "mark-white": 64 };

await mkdir(target, { recursive: true });

for (const file of await readdir(source)) {
  const name = path.parse(file).name;
  if (!(name in HEIGHTS) || !file.endsWith(".png")) continue;

  const out = path.join(target, `${name}.webp`);
  await sharp(path.join(source, file))
    .resize({ height: HEIGHTS[name] * 2 })
    .webp({ quality: 85 })
    .toFile(out);
}

console.log(`Brand assets synced to ${path.relative(process.cwd(), target)}`);
