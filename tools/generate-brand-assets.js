/**
 * Derives every brand asset the web and mobile apps ship from the master
 * lockup in /logos. Re-runnable and idempotent — it overwrites in place, so
 * regenerating after a logo revision is the whole workflow. Nothing under
 * web/public/brand, mobile/assets/brand, the launcher icon sets or the
 * favicons should be edited by hand; edit the source PNG and re-run.
 *
 *   node tools/generate-brand-assets.js
 *
 * Uses sharp, which is not a dependency of its own — it comes in with Next, so
 * run this from a tree where `npm install` has been done in web/:
 *
 *   NODE_PATH=web/node_modules node tools/generate-brand-assets.js
 */
const sharp = require('sharp');
const fs = require('fs');
const path = require('path');

const ROOT = 'c:/Users/Administrator/Desktop/school pilot';
const SRC = path.join(ROOT, 'logos');
const LOCKUP = path.join(SRC, 'school pilot main logo.png');

const mkdir = (p) => fs.mkdirSync(p, { recursive: true });

// Navy ink -> white, amber arrow left alone. Lets one source lockup serve both
// the light surfaces and the navy ones.
async function whiten(input) {
  const { data, info } = await sharp(input)
    .ensureAlpha()
    .raw()
    .toBuffer({ resolveWithObject: true });
  for (let i = 0; i < data.length; i += 4) {
    const [r, g, b, a] = [data[i], data[i + 1], data[i + 2], data[i + 3]];
    if (a === 0) continue;
    const isAmber = r > 140 && b < 130 && r > b + 60;
    if (!isAmber) {
      data[i] = 255;
      data[i + 1] = 255;
      data[i + 2] = 255;
    }
  }
  return sharp(data, { raw: { width: info.width, height: info.height, channels: 4 } })
    .png()
    .toBuffer();
}

// The glow behind the mark on the app-icon tile, rebuilt so it can be rendered
// at any size instead of upscaled from the 967px source.
const tileBg = (size) =>
  Buffer.from(
    `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">` +
      `<defs><radialGradient id="g" cx="50%" cy="42%" r="75%">` +
      `<stop offset="0%" stop-color="#0C3E6E"/>` +
      `<stop offset="55%" stop-color="#012B53"/>` +
      `<stop offset="100%" stop-color="#000E22"/>` +
      `</radialGradient></defs>` +
      `<rect width="${size}" height="${size}" fill="url(#g)"/></svg>`
  );

// PNG-payload .ico (Vista+). sharp has no ico encoder.
function ico(pngs) {
  const dir = Buffer.alloc(6);
  dir.writeUInt16LE(0, 0);
  dir.writeUInt16LE(1, 2);
  dir.writeUInt16LE(pngs.length, 4);
  let offset = 6 + 16 * pngs.length;
  const entries = [];
  for (const { size, buf } of pngs) {
    const e = Buffer.alloc(16);
    e.writeUInt8(size >= 256 ? 0 : size, 0);
    e.writeUInt8(size >= 256 ? 0 : size, 1);
    e.writeUInt8(0, 2);
    e.writeUInt8(0, 3);
    e.writeUInt16LE(1, 4);
    e.writeUInt16LE(32, 6);
    e.writeUInt32LE(buf.length, 8);
    e.writeUInt32LE(offset, 12);
    entries.push(e);
    offset += buf.length;
  }
  return Buffer.concat([dir, ...entries, ...pngs.map((p) => p.buf)]);
}

(async () => {
  // ---- base pieces ----------------------------------------------------
  const lockupNavy = await sharp(LOCKUP).trim({ threshold: 1 }).png().toBuffer();
  const lockupWhite = await whiten(lockupNavy);

  const lockupMeta = await sharp(lockupNavy).metadata();
  const markNavy = await sharp(lockupNavy)
    .extract({ left: 0, top: 0, width: 300, height: lockupMeta.height })
    .trim({ threshold: 1 })
    .png()
    .toBuffer();
  const markWhite = await whiten(markNavy);

  // ---- the app-icon tile, at any size ---------------------------------
  // 62% keeps the book's outer curves clear of Android's circular mask and
  // iOS's corner radius.
  const tile = async (size, { alpha = false } = {}) => {
    const inner = Math.round(size * 0.62);
    const mark = await sharp(markWhite)
      .resize(inner, inner, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .toBuffer();
    const img = sharp(tileBg(size))
      .composite([{ input: mark, gravity: 'centre' }])
      .png();
    return alpha ? img.toBuffer() : img.flatten({ background: '#000E22' }).toBuffer();
  };

  // Transparent foreground for Android's adaptive icon: mark only, sized to
  // the 66dp-of-108dp safe zone.
  const adaptiveFg = async (size) => {
    const inner = Math.round(size * 0.46);
    const mark = await sharp(markWhite)
      .resize(inner, inner, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .toBuffer();
    return sharp({
      create: { width: size, height: size, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } },
    })
      .composite([{ input: mark, gravity: 'centre' }])
      .png()
      .toBuffer();
  };

  const write = (p, buf) => {
    mkdir(path.dirname(p));
    fs.writeFileSync(p, buf);
    console.log('  ' + path.relative(ROOT, p).replace(/\\/g, '/'));
  };

  const lockupAt = (buf, w) =>
    sharp(buf).resize({ width: w, fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } }).png().toBuffer();
  const markAt = (buf, w) => sharp(buf).resize(w, w, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } }).png().toBuffer();

  // ---- shared brand folder (web public + flutter assets) --------------
  const brandFiles = {
    'logo.png': await lockupAt(lockupNavy, 1024),
    'logo-white.png': await lockupAt(lockupWhite, 1024),
    'mark.png': await markAt(markNavy, 512),
    'mark-white.png': await markAt(markWhite, 512),
  };
  console.log('brand lockups:');
  for (const dest of [
    path.join(ROOT, 'web/public/brand'),
    path.join(ROOT, 'mobile/assets/brand'),
  ]) {
    for (const [name, buf] of Object.entries(brandFiles)) write(path.join(dest, name), buf);
  }

  // ---- web app icons (Next 16 file conventions) -----------------------
  console.log('web icons:');
  write(path.join(ROOT, 'web/src/app/icon.png'), await tile(512, { alpha: true }));
  write(path.join(ROOT, 'web/src/app/apple-icon.png'), await tile(180));
  const favicon = ico([
    { size: 16, buf: await tile(16, { alpha: true }) },
    { size: 32, buf: await tile(32, { alpha: true }) },
    { size: 48, buf: await tile(48, { alpha: true }) },
  ]);
  write(path.join(ROOT, 'web/src/app/favicon.ico'), favicon);
  write(path.join(ROOT, 'backend/public/favicon.ico'), favicon);

  // ---- android ---------------------------------------------------------
  console.log('android launcher:');
  const densities = { mdpi: 1, hdpi: 1.5, xhdpi: 2, xxhdpi: 3, xxxhdpi: 4 };
  for (const [d, scale] of Object.entries(densities)) {
    const res = path.join(ROOT, 'mobile/android/app/src/main/res', `mipmap-${d}`);
    write(path.join(res, 'ic_launcher.png'), await tile(Math.round(48 * scale)));
    write(path.join(res, 'ic_launcher_foreground.png'), await adaptiveFg(Math.round(108 * scale)));
  }

  // ---- ios -------------------------------------------------------------
  console.log('ios launcher:');
  const iosDir = path.join(ROOT, 'mobile/ios/Runner/Assets.xcassets/AppIcon.appiconset');
  const contents = JSON.parse(fs.readFileSync(path.join(iosDir, 'Contents.json'), 'utf8'));
  const seen = new Set();
  for (const img of contents.images) {
    if (seen.has(img.filename)) continue;
    seen.add(img.filename);
    const px = Math.round(parseFloat(img.size) * parseInt(img.scale, 10));
    // No alpha: the App Store rejects icons with a transparency channel.
    write(path.join(iosDir, img.filename), await sharp(await tile(px)).removeAlpha().png().toBuffer());
  }

  // LaunchScreen.storyboard paints a hardcoded white view behind this, so the
  // navy mark is the right ink and stays right in iOS dark mode.
  console.log('ios launch image:');
  const launchDir = path.join(ROOT, 'mobile/ios/Runner/Assets.xcassets/LaunchImage.imageset');
  for (const [name, px] of [['LaunchImage.png', 96], ['LaunchImage@2x.png', 192], ['LaunchImage@3x.png', 288]]) {
    write(path.join(launchDir, name), await markAt(markNavy, px));
  }
})();
