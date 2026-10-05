<?php

namespace App\Services\IdCard;

use App\Models\IdCardTemplate;
use App\Services\ReportCard\TemplateException;

/**
 * Ready-made ID card designs a school picks from and colours as its own
 * (TASK 18): three for students, three for staff.
 *
 * Each is an ordinary template bundle in the restricted dialect, so a design
 * picked here is imported, sanitised, previewed, imposed onto sheets and
 * verified exactly like one a designer uploads. Nothing about printing or
 * scanning knows a gallery exists.
 *
 * The rules every design keeps:
 *  - dompdf renders the print run, so layout is floats, tables and absolute
 *    positioning in millimetres. No flexbox, no gradients, no web fonts.
 *    Two dompdf habits found by rendering every design: a block pinned with
 *    `bottom:` that holds floats has no height and spills off the card, and
 *    an image inside a pinned <div> is dropped — so pinned parts are tables,
 *    pinned from the top; and line-height
 *    does not centre text in a fixed-height bar, so bars use padding.
 *  - A long name shrinks, never clips: `holder.name_fit` is normal, long or
 *    very-long, and each design sizes the name from it.
 *  - The QR code is at least 13mm and always on white, so a gate phone in
 *    poor light can still read it.
 *  - Only data SchoolPilot already holds (doc §12). The back carries the
 *    school's number, never a parent's phone or a home address.
 */
class IdCardGallery
{
    public const DESIGNS = [
        'classic-band' => [
            'name' => 'Classic band', 'holder_type' => 'student', 'orientation' => 'landscape',
            'description' => 'School colour band with crest across the top; photo, details and QR below.',
            'primary' => '#0b6b4f', 'accent' => '#f2b705',
        ],
        'portrait-lanyard' => [
            'name' => 'Portrait lanyard', 'holder_type' => 'student', 'orientation' => 'portrait',
            'description' => 'Upright card for a neck lanyard: large photo, name in big letters, class underneath.',
            'primary' => '#1d4ed8', 'accent' => '#f59e0b',
        ],
        'modern-stripe' => [
            'name' => 'Modern stripe', 'holder_type' => 'student', 'orientation' => 'landscape',
            'description' => 'Colour stripe down the side and the QR code on the front for quick gate checks.',
            'primary' => '#7a1f5c', 'accent' => '#e0a100',
        ],
        'formal' => [
            'name' => 'Formal', 'holder_type' => 'staff', 'orientation' => 'landscape',
            'description' => 'Plain and professional, with the job title prominent and a staff bar along the foot.',
            'primary' => '#1f3a5f', 'accent' => '#c8a24a',
        ],
        'staff-lanyard' => [
            'name' => 'Staff lanyard', 'holder_type' => 'staff', 'orientation' => 'portrait',
            'description' => 'Upright card with a solid STAFF bar, so a gate-man tells staff from students at a glance.',
            'primary' => '#1f3a5f', 'accent' => '#b42318',
        ],
        'executive-dark' => [
            'name' => 'Executive dark', 'holder_type' => 'staff', 'orientation' => 'landscape',
            'description' => 'Dark card with light text and a fine accent line, for proprietors and senior staff.',
            'primary' => '#111827', 'accent' => '#c9a227',
        ],
    ];

    /** Slug prefix, so a school's gallery designs are recognisable among its imports. */
    public const SLUG_PREFIX = 'gallery-';

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        return array_map(
            fn (string $key, array $d) => ['key' => $key] + $d,
            array_keys(self::DESIGNS),
            self::DESIGNS
        );
    }

    /**
     * The design as an import bundle, in the school's colours.
     *
     * @throws TemplateException for an unknown design or a colour that is not #rrggbb
     */
    public function bundle(string $key, ?string $primary = null, ?string $accent = null): array
    {
        $design = self::DESIGNS[$key] ?? throw new TemplateException("There is no gallery design called \"{$key}\".");
        $primary = $this->colour($primary ?? $design['primary']);
        $accent = $this->colour($accent ?? $design['accent']);

        [$front, $back, $css] = match ($key) {
            'classic-band' => [$this->classicFront(), $this->back('landscape'), $this->classicCss()],
            'portrait-lanyard' => [$this->lanyardFront(false), $this->back('portrait'), $this->lanyardCss(false)],
            'modern-stripe' => [$this->stripeFront(), $this->back('landscape'), $this->stripeCss()],
            'formal' => [$this->formalFront(), $this->back('landscape'), $this->formalCss()],
            'staff-lanyard' => [$this->lanyardFront(true), $this->back('portrait'), $this->lanyardCss(true)],
            'executive-dark' => [$this->executiveFront(), $this->back('landscape'), $this->executiveCss()],
        };

        $styles = strtr($css."\n".$this->backCss(), [
            '__PRIMARY__' => $primary,
            '__ON_PRIMARY__' => $this->onColour($primary),
            '__ACCENT__' => $accent,
            '__ON_ACCENT__' => $this->onColour($accent),
        ]);

        return [
            'engine' => IdCardTemplate::ENGINE,
            'name' => $design['name'],
            'slug' => self::SLUG_PREFIX.$key,
            'description' => $design['description'],
            'holder_type' => $design['holder_type'],
            'front' => $front,
            'back' => $back,
            'styles' => $styles,
            'card' => [
                'size' => 'CR80',
                'orientation' => $design['orientation'],
                'corner_radius_mm' => 3,
                'photo_box_mm' => $design['orientation'] === 'portrait' ? ['width' => 26, 'height' => 32] : ['width' => 22, 'height' => 28],
            ],
            'regions' => [['gallery' => $key, 'primary' => $primary, 'accent' => $accent]],
        ];
    }

    /**
     * How long a name is, for the design to size it. Counted in characters
     * of the upper-cased name, which is how every design prints it.
     */
    public static function nameFit(string $name): string
    {
        $length = mb_strlen(trim($name));

        return match (true) {
            $length <= 20 => 'normal',
            $length <= 28 => 'long',
            default => 'very-long',
        };
    }

    /** @throws TemplateException */
    private function colour(string $value): string
    {
        $value = strtolower(trim($value));
        if (! preg_match('/^#[0-9a-f]{6}$/', $value)) {
            throw new TemplateException("\"{$value}\" is not a colour. Use the #rrggbb form, e.g. #0b6b4f.");
        }

        return $value;
    }

    /** White or near-black, whichever reads better on this colour (WCAG relative luminance). */
    private function onColour(string $hex): string
    {
        $lin = function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin(hexdec(substr($hex, 1, 2))) + 0.7152 * $lin(hexdec(substr($hex, 3, 2))) + 0.0722 * $lin(hexdec(substr($hex, 5, 2)));

        // Contrast against white vs against #12141a.
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.0627) ? '#ffffff' : '#12141a';
    }

    // ------------------------------------------------------------------
    // Shared pieces
    // ------------------------------------------------------------------

    private function photo(string $class = 'photo'): string
    {
        return <<<TPL
{{#if holder.photo_url}}<img class="{$class}" src="{{ holder.photo_url }}" alt="">{{else}}<div class="{$class} photo-missing"><div class="ph">PHOTO</div></div>{{/if}}
TPL;
    }

    private function qr(string $class = 'qr'): string
    {
        return <<<TPL
<div class="{$class}-tile">{{#if verification.qr_code_url}}<img class="{$class}" src="{{ verification.qr_code_url }}" alt="">{{/if}}</div>
TPL;
    }

    /**
     * The reverse every gallery design shares: the disclaimer that this card
     * is not a key (doc §3), the holder's identifier, the school's contact
     * and a scannable code.
     */
    private function back(string $orientation): string
    {
        $qr = $this->qr('qr-back');
        $foot = $orientation === 'portrait'
            ? <<<TPL
<div class="back-foot">
    <div class="back-contact"><div>{{ school.phone }}</div><div>{{ school.email }}</div></div>
    <div class="back-verify">{$qr}<div class="verify-note">Scan to check this card</div></div>
  </div>
TPL
            : <<<TPL
<table class="back-foot"><tr>
    <td class="back-contact"><div>{{ school.phone }}</div><div>{{ school.email }}</div></td>
    <td class="back-verify">{$qr}<div class="verify-note">Scan to check this card</div></td>
  </tr></table>
TPL;

        return <<<TPL
<div class="card back {$orientation}">
  <div class="back-head">{{ school.name | upper }}</div>
  <div class="back-body">
    <p class="cond">This card remains the property of the school and must be returned on request.</p>
    <p class="cond">It confirms identity only. It is not a key, a payment card or an access pass.</p>
    <p class="cond">If found, please return it to the school or call the number below.</p>
    <table class="back-fields">
      <tr><th>Holder</th><td>{{ holder.name }}</td></tr>
      <tr><th>{{ holder.id_label }}</th><td>{{ holder.id_value | default:"—" }}</td></tr>
      {{#if holder.blood_group}}<tr><th>Blood group</th><td>{{ holder.blood_group }}</td></tr>{{/if}}
      <tr><th>Issued</th><td>{{ card.issued_on | date:"j M Y" }}</td></tr>
      <tr><th>Serial</th><td>{{ card.serial }}</td></tr>
    </table>
  </div>
  {$foot}
</div>
TPL;
    }

    private function backCss(): string
    {
        return <<<'CSS'
.card.back { position: relative; width: 100%; height: 100%; background: #ffffff; color: #12141a; font-family: DejaVu Sans, sans-serif; overflow: hidden; }
.card.back .back-head { background: __PRIMARY__; color: __ON_PRIMARY__; font-size: 5.6pt; font-weight: bold; padding: 1.4mm 2.4mm; letter-spacing: 0.3pt; }
.card.back .back-body { padding: 1.4mm 2.4mm 0; }
.card.back .cond { font-size: 3.9pt; color: #475467; line-height: 1.35; margin: 0 0 0.5mm; }
.card.back .back-fields { width: 100%; border-collapse: collapse; font-size: 4.3pt; margin-top: 0.8mm; }
.card.back .back-fields th { text-align: left; color: #667085; font-weight: normal; width: 15mm; padding: 0.25mm 0; }
.card.back .back-fields td { font-weight: bold; padding: 0.25mm 0; word-wrap: break-word; }
.card.back.landscape .back-foot { position: absolute; left: 2.4mm; top: 37mm; width: 80.8mm; border-collapse: collapse; }
.card.back.landscape .back-contact { font-size: 4.1pt; color: #475467; line-height: 1.35; vertical-align: bottom; padding: 0; }
.card.back.landscape .back-verify { text-align: center; vertical-align: bottom; width: 14mm; padding: 0; }
.card.back .qr-back-tile { background: #ffffff; padding: 0.5mm; border: 0.25mm solid #d0d5dd; }
.card.back .qr-back { width: 12mm; height: 12mm; }
.card.back .verify-note { font-size: 3.2pt; color: #667085; margin-top: 0.3mm; }
.card.back.portrait .back-head { text-align: center; padding: 2.4mm; }
.card.back.portrait .back-foot { position: absolute; left: 2.4mm; right: 2.4mm; top: 60mm; text-align: center; }
.card.back.portrait .back-contact { font-size: 4.1pt; color: #475467; line-height: 1.35; padding-bottom: 1.2mm; }
.card.back.portrait .qr-back-tile { width: 13.2mm; margin: 0 auto; }
.card .photo-missing { color: #98a2b3; font-size: 5pt; border: 0.3mm dashed #98a2b3; background: #f9fafb; text-align: center; }
.card .photo-missing .ph { padding-top: 11mm; }
CSS;
    }

    // ------------------------------------------------------------------
    // Students
    // ------------------------------------------------------------------

    private function classicFront(): string
    {
        $photo = $this->photo();
        $qr = $this->qr();

        return <<<TPL
<div class="card face classic">
  <div class="band">
    {{#if school.logo_url}}<img class="crest" src="{{ school.logo_url }}" alt="">{{/if}}
    <div class="band-text">
      <div class="school-name">{{ school.name | upper }}</div>
      <div class="school-line">{{ school.address }}</div>
    </div>
  </div>
  <div class="rule"></div>
  <div class="body">
    <div class="photo-frame">{$photo}</div>
    <div class="details">
      <div class="holder-name fit-{{ holder.name_fit }}">{{ holder.name | upper }}</div>
      {{#if holder.class}}<div class="holder-role">{{ holder.class }} {{ holder.arm }}</div>{{/if}}
      <table class="fields">
        <tr><th>{{ holder.id_label }}</th><td>{{ holder.id_value | default:"—" }}</td></tr>
        <tr><th>Session</th><td>{{ card.session | default:"—" }}</td></tr>
        <tr><th>Valid to</th><td>{{ card.expires_on | date:"M Y" | default:"until revoked" }}</td></tr>
      </table>
    </div>
    <div class="qr-frame">{$qr}<div class="serial">{{ card.serial }}</div></div>
  </div>
  <div class="foot">STUDENT</div>
</div>
TPL;
    }

    private function classicCss(): string
    {
        return <<<'CSS'
.card.classic { position: relative; width: 100%; height: 100%; font-family: DejaVu Sans, sans-serif; color: #12141a; background: #ffffff; overflow: hidden; }
.classic .band { background: __PRIMARY__; color: __ON_PRIMARY__; padding: 1.6mm 2.4mm; height: 7.4mm; }
.classic .crest { width: 7mm; height: 7mm; vertical-align: middle; margin-right: 1.6mm; }
.classic .band-text { display: inline-block; vertical-align: middle; width: 68mm; }
.classic .school-name { font-size: 7pt; font-weight: bold; letter-spacing: 0.3pt; line-height: 1.15; }
.classic .school-line { font-size: 4.3pt; line-height: 1.2; }
.classic .rule { height: 0.8mm; background: __ACCENT__; }
.classic .body { padding: 2mm 2.4mm 0; }
.classic .photo-frame { float: left; width: 22mm; margin-right: 2.4mm; }
.classic .photo { width: 22mm; height: 28mm; border: 0.3mm solid __PRIMARY__; }
.classic .details { float: left; width: 37mm; }
.classic .holder-name { font-weight: bold; line-height: 1.12; word-wrap: break-word; font-size: 8pt; }
.classic .holder-name.fit-long { font-size: 6.6pt; }
.classic .holder-name.fit-very-long { font-size: 5.6pt; }
.classic .holder-role { font-size: 5.6pt; color: __PRIMARY__; font-weight: bold; margin: 0.6mm 0 1mm; }
.classic .fields { width: 100%; border-collapse: collapse; font-size: 4.7pt; }
.classic .fields th { text-align: left; color: #667085; font-weight: normal; padding: 0.2mm 0; width: 13mm; }
.classic .fields td { padding: 0.2mm 0; font-weight: bold; }
.classic .qr-frame { float: right; width: 16mm; text-align: center; }
.classic .qr-tile { background: #ffffff; }
.classic .qr { width: 14mm; height: 14mm; }
.classic .serial { font-size: 3.6pt; color: #667085; margin-top: 0.5mm; word-wrap: break-word; }
.classic .foot { position: absolute; left: 0; top: 50.4mm; width: 85.6mm; padding: 0.9mm 0; background: __PRIMARY__; color: __ON_PRIMARY__; font-size: 4.4pt; font-weight: bold; letter-spacing: 1.2pt; text-align: center; }
CSS;
    }

    /** The upright lanyard card. Staff get the solid STAFF bar; students their class. */
    private function lanyardFront(bool $staff): string
    {
        $photo = $this->photo();
        $qr = $this->qr();
        $role = $staff
            ? '{{#if holder.designation}}<div class="holder-role">{{ holder.designation }}</div>{{/if}}'
            : '{{#if holder.class}}<div class="holder-role">{{ holder.class }} {{ holder.arm }}</div>{{/if}}';
        $bar = $staff ? '<div class="staff-bar">STAFF</div>' : '<div class="student-bar">STUDENT</div>';

        return <<<TPL
<div class="card face lanyard">
  <div class="head">
    {{#if school.logo_url}}<img class="crest" src="{{ school.logo_url }}" alt="">{{/if}}
    <div class="school-name">{{ school.name | upper }}</div>
  </div>
  <div class="photo-frame">{$photo}</div>
  <div class="holder-name fit-{{ holder.name_fit }}">{{ holder.name | upper }}</div>
  {$role}
  {$bar}
  <table class="foot"><tr>
    <td class="foot-fields">
      <div class="label">{{ holder.id_label }}</div><div class="value">{{ holder.id_value | default:"—" }}</div>
      <div class="label">Valid to</div><div class="value">{{ card.expires_on | date:"M Y" | default:"until revoked" }}</div>
    </td>
    <td class="foot-qr">{$qr}</td>
  </tr></table>
</div>
TPL;
    }

    private function lanyardCss(bool $staff): string
    {
        // Students: the bar is the accent, quietly. Staff: a solid bar of the
        // accent colour, the thing a gate-man actually looks for.
        $barPad = $staff ? '1.3mm' : '0.8mm';
        $barSize = $staff ? '9pt' : '4.6pt';

        return <<<CSS
.card.lanyard { position: relative; width: 100%; height: 100%; font-family: DejaVu Sans, sans-serif; color: #12141a; background: #ffffff; overflow: hidden; text-align: center; }
.lanyard .head { background: __PRIMARY__; color: __ON_PRIMARY__; padding: 2.6mm 2mm 1.4mm; }
.lanyard .crest { width: 7.4mm; height: 7.4mm; display: block; margin: 0 auto 0.6mm; }
.lanyard .school-name { font-size: 5.8pt; font-weight: bold; line-height: 1.15; letter-spacing: 0.3pt; }
.lanyard .photo-frame { margin: 2mm auto 1.2mm; width: 24mm; }
.lanyard .photo { width: 24mm; height: 29mm; border: 0.5mm solid __ACCENT__; }
.lanyard .holder-name { font-weight: bold; line-height: 1.12; padding: 0 2mm; word-wrap: break-word; font-size: 8.4pt; }
.lanyard .holder-name.fit-long { font-size: 6.8pt; }
.lanyard .holder-name.fit-very-long { font-size: 5.8pt; }
.lanyard .holder-role { font-size: 5.6pt; color: __PRIMARY__; font-weight: bold; margin-top: 0.6mm; }
.lanyard .staff-bar, .lanyard .student-bar { background: __ACCENT__; color: __ON_ACCENT__; padding: {$barPad} 0; font-size: {$barSize}; font-weight: bold; letter-spacing: 1.6pt; margin-top: 1.2mm; }
.lanyard .foot { position: absolute; left: 2.6mm; top: 68.6mm; width: 48.8mm; border-collapse: collapse; }
.lanyard .foot-fields { text-align: left; vertical-align: bottom; padding: 0; }
.lanyard .foot-fields .label { font-size: 4pt; color: #667085; }
.lanyard .foot-fields .value { font-size: 4.6pt; font-weight: bold; margin-bottom: 0.6mm; }
.lanyard .foot-qr { text-align: right; vertical-align: bottom; width: 14mm; padding: 0; }
.lanyard .qr-tile { background: #ffffff; }
.lanyard .qr { width: 14mm; height: 14mm; }
CSS;
    }

    private function stripeFront(): string
    {
        $photo = $this->photo();
        $qr = $this->qr();

        return <<<TPL
<div class="card face stripe">
  <div class="side">
    {{#if school.logo_url}}<img class="crest" src="{{ school.logo_url }}" alt="">{{/if}}
  </div>
  <div class="main">
    <div class="school-name">{{ school.name | upper }}</div>
    <div class="label">STUDENT IDENTITY CARD</div>
    <div class="photo-frame">{$photo}</div>
    <div class="details">
      <div class="holder-name fit-{{ holder.name_fit }}">{{ holder.name | upper }}</div>
      {{#if holder.class}}<div class="holder-role">{{ holder.class }} {{ holder.arm }}</div>{{/if}}
      <table class="fields">
        <tr><th>{{ holder.id_label }}</th><td>{{ holder.id_value | default:"—" }}</td></tr>
        <tr><th>Valid to</th><td>{{ card.expires_on | date:"M Y" | default:"until revoked" }}</td></tr>
      </table>
    </div>
    <div class="qr-col">{$qr}<div class="serial">{{ card.serial }}</div></div>
  </div>
</div>
TPL;
    }

    private function stripeCss(): string
    {
        return <<<'CSS'
.card.stripe { position: relative; width: 100%; height: 100%; font-family: DejaVu Sans, sans-serif; color: #12141a; background: #ffffff; overflow: hidden; }
.stripe .side { position: absolute; left: 0; top: 0; bottom: 0; width: 9mm; background: __PRIMARY__; border-right: 1mm solid __ACCENT__; text-align: center; }
.stripe .crest { width: 6.4mm; height: 6.4mm; margin-top: 2.4mm; }
.stripe .main { margin-left: 12.4mm; padding-top: 2.2mm; width: 71mm; }
.stripe .school-name { font-size: 6.4pt; font-weight: bold; color: __PRIMARY__; line-height: 1.15; }
.stripe .label { font-size: 3.9pt; letter-spacing: 0.9pt; color: #667085; margin: 0.4mm 0 1.6mm; }
.stripe .photo-frame { float: left; width: 20mm; margin-right: 2.2mm; }
.stripe .photo { width: 20mm; height: 25mm; }
.stripe .details { float: left; width: 29mm; }
.stripe .holder-name { font-weight: bold; line-height: 1.12; word-wrap: break-word; font-size: 7.4pt; }
.stripe .holder-name.fit-long { font-size: 6.2pt; }
.stripe .holder-name.fit-very-long { font-size: 5.3pt; }
.stripe .holder-role { display: inline-block; background: __ACCENT__; color: __ON_ACCENT__; font-size: 5pt; font-weight: bold; padding: 0.3mm 1.2mm; margin: 0.8mm 0 1mm; }
.stripe .fields { border-collapse: collapse; font-size: 4.5pt; }
.stripe .fields th { text-align: left; color: #667085; font-weight: normal; padding: 0.2mm 1.2mm 0.2mm 0; }
.stripe .fields td { font-weight: bold; padding: 0.2mm 0; }
.stripe .qr-col { float: left; width: 17.6mm; margin-left: 1.2mm; padding-top: 9mm; text-align: center; }
.stripe .qr-tile { background: #ffffff; }
.stripe .qr { width: 15mm; height: 15mm; }
.stripe .serial { font-size: 3.4pt; color: #667085; margin-top: 0.4mm; word-wrap: break-word; }
CSS;
    }

    // ------------------------------------------------------------------
    // Staff
    // ------------------------------------------------------------------

    private function formalFront(): string
    {
        $photo = $this->photo();

        return <<<TPL
<div class="card face formal">
  <div class="head">
    {{#if school.logo_url}}<img class="crest" src="{{ school.logo_url }}" alt="">{{/if}}
    <div class="head-text">
      <div class="school-name">{{ school.name | upper }}</div>
      <div class="school-line">{{ school.address }}</div>
    </div>
  </div>
  <div class="rule"></div>
  <div class="body">
    <div class="details">
      <div class="holder-name fit-{{ holder.name_fit }}">{{ holder.name }}</div>
      {{#if holder.designation}}<div class="holder-role">{{ holder.designation }}</div>{{/if}}
      <table class="fields">
        <tr><th>{{ holder.id_label }}</th><td>{{ holder.id_value | default:"—" }}</td></tr>
        <tr><th>Valid to</th><td>{{ card.expires_on | date:"M Y" | default:"until revoked" }}</td></tr>
        <tr><th>Serial</th><td>{{ card.serial }}</td></tr>
      </table>
    </div>
    <div class="photo-frame">{$photo}</div>
  </div>
  <div class="foot">STAFF IDENTITY CARD</div>
</div>
TPL;
    }

    private function formalCss(): string
    {
        return <<<'CSS'
.card.formal { position: relative; width: 100%; height: 100%; font-family: DejaVu Sans, sans-serif; color: #12141a; background: #ffffff; overflow: hidden; }
.formal .head { padding: 2.2mm 2.6mm 1.4mm; }
.formal .crest { width: 7.4mm; height: 7.4mm; vertical-align: middle; margin-right: 1.8mm; }
.formal .head-text { display: inline-block; vertical-align: middle; width: 66mm; }
.formal .school-name { font-size: 6.8pt; font-weight: bold; color: __PRIMARY__; letter-spacing: 0.3pt; line-height: 1.15; }
.formal .school-line { font-size: 4.2pt; color: #667085; line-height: 1.2; }
.formal .rule { height: 0.5mm; background: __ACCENT__; margin: 0 2.6mm; }
.formal .body { padding: 2mm 2.6mm 0; }
.formal .details { float: left; width: 52mm; }
.formal .holder-name { font-weight: bold; line-height: 1.12; word-wrap: break-word; font-size: 8.6pt; }
.formal .holder-name.fit-long { font-size: 7pt; }
.formal .holder-name.fit-very-long { font-size: 6pt; }
.formal .holder-role { font-size: 6pt; color: __PRIMARY__; font-weight: bold; margin: 0.8mm 0 1.6mm; }
.formal .fields { border-collapse: collapse; font-size: 4.7pt; }
.formal .fields th { text-align: left; color: #667085; font-weight: normal; padding: 0.25mm 2mm 0.25mm 0; }
.formal .fields td { font-weight: bold; padding: 0.25mm 0; }
.formal .photo-frame { float: right; width: 21mm; }
.formal .photo { width: 21mm; height: 26mm; border: 0.3mm solid __ACCENT__; }
.formal .foot { position: absolute; left: 0; top: 49mm; width: 85.6mm; padding: 1.5mm 0; background: __PRIMARY__; color: __ON_PRIMARY__; font-size: 5pt; font-weight: bold; letter-spacing: 1.4pt; text-align: center; }
CSS;
    }

    private function executiveFront(): string
    {
        $photo = $this->photo();
        $qr = $this->qr();

        return <<<TPL
<div class="card face executive">
  <div class="head">
    {{#if school.logo_url}}<img class="crest" src="{{ school.logo_url }}" alt="">{{/if}}
    <div class="school-name">{{ school.name | upper }}</div>
  </div>
  <div class="line"></div>
  <div class="body">
    <div class="photo-frame">{$photo}</div>
    <div class="details">
      <div class="holder-name fit-{{ holder.name_fit }}">{{ holder.name }}</div>
      {{#if holder.designation}}<div class="holder-role">{{ holder.designation | upper }}</div>{{/if}}
      <table class="fields">
        <tr><th>{{ holder.id_label }}</th><td>{{ holder.id_value | default:"—" }}</td></tr>
        <tr><th>Valid to</th><td>{{ card.expires_on | date:"M Y" | default:"until revoked" }}</td></tr>
      </table>
    </div>
    <div class="qr-frame">{$qr}</div>
  </div>
  <div class="mark">STAFF</div>
</div>
TPL;
    }

    private function executiveCss(): string
    {
        return <<<'CSS'
.card.executive { position: relative; width: 100%; height: 100%; font-family: DejaVu Sans, sans-serif; color: __ON_PRIMARY__; background: __PRIMARY__; overflow: hidden; }
.executive .head { padding: 2.4mm 2.6mm 1.4mm; }
.executive .crest { width: 7mm; height: 7mm; vertical-align: middle; margin-right: 1.8mm; }
.executive .school-name { display: inline-block; vertical-align: middle; font-size: 6.6pt; font-weight: bold; letter-spacing: 0.6pt; width: 66mm; }
.executive .line { height: 0.35mm; background: __ACCENT__; margin: 0 2.6mm; }
.executive .body { padding: 2.4mm 2.6mm 0; }
.executive .photo-frame { float: left; width: 21mm; margin-right: 2.6mm; }
.executive .photo { width: 21mm; height: 26mm; border: 0.5mm solid __ACCENT__; }
.executive .details { float: left; width: 38mm; }
.executive .holder-name { font-weight: bold; line-height: 1.12; word-wrap: break-word; font-size: 8.2pt; }
.executive .holder-name.fit-long { font-size: 6.8pt; }
.executive .holder-name.fit-very-long { font-size: 5.8pt; }
.executive .holder-role { font-size: 4.8pt; color: __ACCENT__; font-weight: bold; letter-spacing: 0.6pt; margin: 0.8mm 0 1.6mm; }
.executive .fields { border-collapse: collapse; font-size: 4.6pt; }
.executive .fields th { text-align: left; font-weight: normal; padding: 0.25mm 2mm 0.25mm 0; }
.executive .fields td { font-weight: bold; padding: 0.25mm 0; }
.executive .qr-frame { float: right; width: 15.4mm; }
.executive .qr-tile { background: #ffffff; padding: 0.7mm; }
.executive .qr { width: 14mm; height: 14mm; }
.executive .mark { position: absolute; left: 2.6mm; top: 48.6mm; font-size: 5pt; font-weight: bold; letter-spacing: 2pt; color: __ACCENT__; }
.executive .photo-missing { background: #ffffff; }
CSS;
    }
}
