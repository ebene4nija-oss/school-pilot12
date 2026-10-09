/**
 * Fails when the built homepage breaks its weight budget (Landing PRD §14.3):
 * at most 145 KB of gzipped JavaScript and 800 KB in total on first load.
 *
 * The spec first said 90 KB of JavaScript, but React and the Next.js App
 * Router alone ship about 125 KB before any of the site's own code (measured
 * October 2026: React DOM 70 KB, router runtime 44 KB, bootstrap ~10 KB). 145 KB
 * is that floor plus our own components with a little headroom: the point is
 * that the site cannot creep heavier unnoticed. Raise it only on purpose.
 *
 * Reads out/index.html after `npm run build` and adds up every script,
 * stylesheet, font and image it references from the export. Lighthouse CI
 * covers LCP, INP and CLS; this is the cheap check that runs anywhere.
 */
import { readFile, stat } from "node:fs/promises";
import path from "node:path";
import { gzipSync } from "node:zlib";

const OUT = path.resolve("out");
const JS_BUDGET = Number(process.env.BUDGET_JS_KB ?? 145) * 1024;
const TOTAL_BUDGET = 800 * 1024;

const html = await readFile(path.join(OUT, "index.html"), "utf8");
// Legacy polyfills load with `nomodule`: modern browsers never fetch them.
const legacy = new Set([...html.matchAll(/<script[^>]*src="([^"]+)"[^>]*noModule/gi)].map((m) => m[1]));
const refs = new Set(
  [...html.matchAll(/(?:src|href)="(\/[^"#?]+\.(?:js|css|woff2|webp|avif|png|svg))"/g)]
    .map((m) => m[1])
    .filter((ref) => !legacy.has(ref)),
);

let js = 0;
let total = gzipSync(html).length;

for (const ref of refs) {
  const file = path.join(OUT, ref);
  try {
    await stat(file);
  } catch {
    continue;
  }
  const body = await readFile(file);
  const size = /\.(js|css|svg)$/.test(ref) ? gzipSync(body).length : body.length;
  if (ref.endsWith(".js")) js += size;
  total += size;
}

const kb = (n) => `${(n / 1024).toFixed(1)} KB`;
console.log(`Homepage JS (gzip): ${kb(js)} / ${kb(JS_BUDGET)}`);
console.log(`Homepage total:     ${kb(total)} / ${kb(TOTAL_BUDGET)}`);

if (js > JS_BUDGET || total > TOTAL_BUDGET) {
  console.error("Performance budget exceeded.");
  process.exit(1);
}
