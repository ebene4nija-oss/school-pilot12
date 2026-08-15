# ID Card Template Contract

**Engine:** `schoolpilot-id-card/v1`

Every SchoolPilot school can code its own ID card design and import it. This
document is the contract that design must be written against: what the markup
may contain, what data it can reach, what it is obliged to include, and how the
finished cards are laid onto paper.

A school that imports nothing gets the shipped default design, which is also
the worked example at the end of this document.

If you have already read `report-card-template-contract.md`, most of §3 will be
familiar — it is the same dialect, deliberately. The parts that are new to ID
cards are §1, §5 (geometry), §6 (imposition) and §8 (the lifecycle).

---

## 1. What this card is, and what it is not

**It is not an access-control credential.** SchoolPilot has no readers, no RFID
and no biometrics, and never will (doc §3). This card carries no secret that
opens anything. It is a printed, laminated statement that the school issued it,
plus a QR code that lets anybody check whether that is still true.

That single decision explains most of what follows. There is no key material to
protect, so the design can be entirely the school's. There *is* an identity
claim to protect, so the verification code and the holder's name are not
negotiable, and the public verification page shows the least it can.

**It is printed on paper the school already buys.** Ten cards to an A4 sheet on
the office printer, cut with scissors or a guillotine, dropped into pouches
from the same market stall as everything else. Not card stock, not a card
printer. That is why §6 exists.

**A design is data, never code.** A school-supplied file is rendered on our
servers, on infrastructure shared with every other tenant. Blade or Twig would
make an uploaded card design a remote code execution vector. So the dialect
below has no expression evaluation at all — no function calls, no arithmetic,
no property access on objects.

**Fail at import, not at print.** Every check that can run at import time does:
parse errors, missing required content, unsafe markup, geometry that will not
fit on a sheet, and a full dry-run render against sample data.

**Nothing goes live by accident.** An import lands as a `draft`. It becomes the
school's design only when an admin activates it, and exactly one design is
active *per holder type* — so activating a new staff card does not silently
change what the students get.

---

## 2. The bundle

A bundle is a single JSON document. Upload it as the request body or as a
`bundle` file to `POST /api/v1/id-cards/templates`.

```json
{
  "engine": "schoolpilot-id-card/v1",
  "name": "Graceland Student Card",
  "slug": "graceland-student",
  "version": 1,
  "holder_type": "student",
  "description": "CR80 landscape, house colours, QR on the back.",
  "card": {
    "size": "CR80",
    "orientation": "landscape",
    "bleed_mm": 0,
    "corner_radius_mm": 3,
    "photo_box_mm": { "width": 22, "height": 28 }
  },
  "regions": ["band", "photo", "details", "qr"],
  "styles": ".card { font-size: 8pt; } ...",
  "front": "<div class=\"card\"> ... </div>",
  "back": "<div class=\"card back\"> ... </div>"
}
```

| Field | Required | Notes |
|---|---|---|
| `engine` | yes | Must match exactly. A mismatched engine is refused, not coerced. |
| `name` | yes | Shown to admins. |
| `holder_type` | no | `student` (default) or `staff`. One active design per type. |
| `slug` | no | Derived from `name` if omitted. |
| `version` | no | Integer. Omit and the next version for that slug is assigned. Re-importing an existing `slug` + `version` is refused — bump the version. |
| `card` | no | Geometry. See §5. |
| `regions` | no | Declarative, for the admin UI. Does not affect rendering. |
| `styles` | no | CSS. Max 100 KB. |
| `front` | yes | Card face markup. Max 200 KB. |
| `back` | no | Reverse markup. Omit for single-sided cards. Max 200 KB. |

### Required content

A design that omits either of these is refused at import:

| Token | Why it cannot be designed away |
|---|---|
| `{{ holder.name }}` | A card with no name identifies nobody. |
| `{{ verification.qr_code_url }}` **or** `{{ verification.verify_url }}` | The ability to check a card against the register is the entire reason this is a product feature and not a Word template. |

The verification token may appear on **either side** — most schools put it on
the back, which is fine.

---

## 3. Template syntax

Identical to the report card dialect. Summarised here; see
`report-card-template-contract.md` §3 for the full treatment.

### Interpolation

```
{{ holder.name }}
```

Always HTML-escaped. There is no unescaped variant — `{{{ ... }}}` is refused
at import.

### Filters

```
{{ holder.name | upper }}
{{ card.expires_on | date:"M Y" }}
{{ holder.id_value | default:"—" }}
{{ holder.name | initials }}
```

Available: `upper`, `lower`, `title`, `trim`, `initials`, `ordinal`, `number`,
`percent`, `naira`, `date`, `default`. Chainable with `|`. An unknown filter is
a warning at import and is ignored at render.

### Blocks

```
{{#if holder.photo_url}} ... {{else}} ... {{/if}}
{{#unless card.expires_on}} ... {{/unless}}
{{#each something}} {{ this.name }} {{@number}} {{/each}}
{{! a comment }}
```

`{{#each}}` is available but rarely useful on a card — there is no list-shaped
data in the ID card context.

### What is not available

No JavaScript. No web fonts. No remote images. No `<script>`, `<iframe>`,
`<object>`, `<embed>`, `<form>`, inline event handlers, or `javascript:` URLs —
each is refused at import with a message naming what was found.

Images must be **embedded**: `data:image/png;base64,…` and friends. The PDF
renderer runs with remote fetching disabled, so an `<img>` pointing at a URL
prints as a blank box. The school crest and the holder's photo are supplied to
you already embedded (§4) — you do not need to do anything about that.

---

## 4. The data model

Everything a design can see. `holder.*` has the same shape for a student and a
member of staff, so one dialect covers both; fields that do not apply are
present and empty rather than absent.

### `school`

| Token | Notes |
|---|---|
| `school.name` | |
| `school.address` | |
| `school.phone` | |
| `school.email` | |
| `school.logo_url` | Already a `data:` URI, or empty. Guard with `{{#if}}`. |
| `school.subdomain` | |

### `holder`

| Token | Student | Staff |
|---|---|---|
| `holder.type` | `student` | `staff` |
| `holder.name` | Full name | Full name |
| `holder.photo_url` | `data:` URI, or empty | same |
| `holder.gender` | | |
| `holder.id_label` | `Admission No.` | `Staff ID` |
| `holder.id_value` | admission number | staff id |
| `holder.class` | e.g. `JSS 2` | *(empty)* |
| `holder.arm` | e.g. `Gold` | *(empty)* |
| `holder.designation` | *(empty)* | e.g. `Head of Mathematics` |
| `holder.admission_number` | | *(empty)* |
| `holder.staff_id` | *(empty)* | |
| `holder.blood_group` | **only when the school opts in** — see below | *(never)* |

`holder.photo_url` is empty when the school has no photo on file and the run
was queued with `allow_missing_photos`. Always guard it:

```
{{#if holder.photo_url}}
<img class="photo" src="{{ holder.photo_url }}" alt="">
{{else}}
<div class="photo-missing">PHOTO</div>
{{/if}}
```

> **Blood group is off by default.** It is one of the fields the NDPA section
> of the product doc (§12) singles out for application-level encryption, and an
> ID card is the least controlled place any field can end up — lost in a
> market, photographed, picked up by a stranger. Nigerian school cards
> commonly carry it and in a real emergency it is genuinely useful, so the
> school decides: a print run sent with `"include_blood_group": true` populates
> `{{ holder.blood_group }}`, and nothing else does. Design for its absence.

### `card`

| Token | Notes |
|---|---|
| `card.serial` | e.g. `GRACELAND/STU/2026/0001` |
| `card.issued_on` | `YYYY-MM-DD`. Use the `date` filter. |
| `card.expires_on` | `YYYY-MM-DD`, or empty for a card valid until revoked |
| `card.session` | e.g. `2026/2027` |
| `card.status` | `active` at print time |

### `verification`

| Token | Notes |
|---|---|
| `verification.qr_code_url` | `data:image/png` URI of the QR. Empty if generation failed — guard it and print the URL as text instead. |
| `verification.verify_url` | The URL the QR encodes |
| `verification.token` | Rarely needed directly |

### Other

`emergency.phone` (the school's number unless overridden), `generated_at`.

---

## 5. Card geometry

```json
"card": {
  "size": "CR80",
  "orientation": "landscape",
  "bleed_mm": 0,
  "corner_radius_mm": 3,
  "photo_box_mm": { "width": 22, "height": 28 }
}
```

| Field | Default | Notes |
|---|---|---|
| `size` | `CR80` | `CR80` (85.6×54mm, the bank-card size), `CR79` (83.9×51), `CR100` (98.5×67). Or give `width_mm`/`height_mm` directly. |
| `width_mm` / `height_mm` | from `size` | Clamped to 40–120mm. |
| `orientation` | `landscape` | `portrait` swaps the axes — a portrait CR80 is 54×85.6mm. |
| `bleed_mm` | `0` | Only meaningful at a commercial printer. Clamped 0–5. |
| `corner_radius_mm` | `3` | Clamped 0–6. Cosmetic in the PDF; the card is cut square unless the school has a corner punch. |
| `photo_box_mm` | `22×28` | **Declare this honestly.** It is what the photo preflight uses to work out the DPI a photo will actually print at, and therefore whether a school gets warned before printing 400 soft portraits. |

Geometry is stored **resolved**, in absolute millimetres. A card cut to 85.6mm
in 2026 still measures 85.6mm if the named-size table is ever edited.

### Writing the CSS

Use millimetres. A card is a physical object; a photo window specified in
pixels stops being 22mm wide the moment the sheet DPI changes.

Your root element is sized by the slot, so start it at `width: 100%; height:
100%` and `overflow: hidden`. Anything that overflows is clipped at the trim
line — it does not push the next card off its cut mark.

---

## 6. How cards get onto paper

This is the part that makes the printed output usable, and the part a design
has to cooperate with.

Cards are **imposed**: many to a sheet, with cut guides. A CR80 card on A4
portrait with 10mm margins gives a 2 × 5 grid — ten cards per sheet. The grid
is centred in the usable area, because a centred grid survives the few
millimetres of feed skew an office printer introduces.

Imposition options are set per print run, not in the bundle:

| Option | Default | Notes |
|---|---|---|
| `sheet` | `A4` | `A4`, `A3`, `LETTER`, `LEGAL` |
| `sheet_orientation` | `portrait` | |
| `margin_mm` | `10` | Floored at 5mm — almost no office printer images closer than that to the paper edge. |
| `gutter_mm` | `0` | Space between cards. |
| `cut_guides` | `border` | `border` (a hairline on the trim line — universally understood, cut on the line), `marks` (corner ticks outside the trim, better with a guillotine, forces a gutter wide enough to hold them), `none`. |
| `duplex` | `long-edge` | See below. |
| `duplex_offset_mm` | `{x:0,y:0}` | Registration correction, ±10mm. |

### Duplex, and why it matters

If the design has a `back`, the run produces alternating front and back sheets.
A duplex printer flipping on the **long edge** mirrors the sheet horizontally —
so the back of the card printed top-left comes out top-right. The imposition
engine reverses each row of backs to compensate. Flipping on the **short edge**
mirrors vertically instead, and the rows are reversed.

Get this wrong and every card in the run is somebody else's, which is only
discovered after the guillotine. Set `duplex` to match what the school's
printer actually does; if in doubt, print one sheet and check before running
the class.

Sheets are padded to full even when the last one is half empty, so a partial
final sheet still puts each back opposite its own front.

---

## 7. Photos

Before a run renders, every holder's photo is resolved and measured. A run
stops and reports rather than printing rubbish.

| Problem | Result |
|---|---|
| No photo on file | **Blocks** the holder |
| File missing from the server | **Blocks** |
| Not a JPEG/PNG/WebP/GIF, or corrupt | **Blocks** |
| Larger than 8 MB | **Blocks** |
| Stored as a web address rather than a file | **Blocks** — the PDF renderer cannot fetch it, and we will not make the server request an arbitrary URL |
| Below ~200 dpi at the declared `photo_box_mm` | Warns, still prints |
| Aspect ratio well outside the photo window | Warns (it will be cropped noticeably) |
| Over 1 MB | Warns (slow run, heavy PDF) |

A school that wants to print anyway sends `allow_missing_photos: true` (photo
problems become empty `holder.photo_url`) and/or `proceed_with_blocked: true`
(print everyone who can be printed). Neither is the default, because printing
38 of 42 cards without saying so is how four children end up with nothing and
nobody notices until the cards are handed out.

EXIF and other embedded metadata are stripped from every photo on the way in —
a phone portrait carries GPS coordinates for wherever it was taken, frequently
the child's home, and that does not belong in a document the school hands out.

---

## 8. Lifecycle

A card is **issued once** and may be **printed many times**. Reprinting a class
reuses the cards the holders already carry rather than minting new serials —
otherwise a reprint to catch four new arrivals would silently orphan the cards
the other 38 children are holding.

| Status | Means |
|---|---|
| `active` | Current. Scans as valid. |
| `expired` | Past `expires_on`. Computed live, so it is true the day it becomes true, not the day a cron notices. |
| `revoked` | Withdrawn by the school. |
| `replaced` | Superseded by a newer card. |

A student card is cut to the end of the academic session by default — a child's
class changes in September, and a card that outlives its own contents is worse
than no card. A staff card has no natural end and runs until revoked.

**Revocation reasons are a fixed list**, not free text: `lost`, `stolen`,
`damaged`, `left_school`, `data_error`, `other`. The verification page is
public, so whatever is stored here is readable by anyone who scans the card. A
category is a fact about a piece of plastic; a sentence typed by a bursar is a
fact about a child, published to whoever picked it up.

`POST /api/v1/id-cards/{id}/replace` revokes and reissues in one step, linking
the new card to the old one so a replacement chain is walkable.

---

## 9. What a scan shows

`GET /api/v1/verify-id/{token}` — public, rate limited, no login. It has to be:
the people who check cards are gatekeepers, bus drivers and receptionists, none
of whom have an account.

It returns a phone-sized HTML page (a camera opens a URL in a browser, so JSON
would be the wrong answer to a scan; add `?format=json` if you want the data).
It shows:

- whether the card is current, in words and in colour
- the holder's name, photo, class or role, and school
- the serial, issue date and expiry

and **nothing else**. No date of birth, no address, no admission number, no
guardian, nothing medical. All of it is already printed on the card the scanner
is holding, so the page adds no disclosure beyond confirming the card is
genuine. The photo is withheld once a card is dead.

An unknown token renders the same "not valid" page as a revoked one, so the
endpoint cannot be walked to learn which tokens exist.

The page says, in print, that it confirms the card and not the person, and that
the card is not a key. That wording is load-bearing: the failure mode of a
convincing "VALID" page is a school starting to treat the card as an access
pass, which is precisely what doc §3 rules out.

---

## 10. Print-run PDFs are deleted after a week

A run's PDF is a page of children's faces and full names — the most sensitive
artefact this product writes to disk. It stays downloadable for seven days and
is then deleted by `id-cards:sweep`. The issuance records are the permanent
history; the sheets are not. A school that needs them again queues the run
again, which costs nothing.

---

## 11. Worked example — the shipped default

This is the design a school gets if it imports nothing. It is also the fastest
starting point: fetch it from
`GET /api/v1/id-cards/template-contract`, which returns the live default for
both holder types along with this deployment's limits and sample context.

### Front

```html
<div class="card face">
  <div class="band">
    {{#if school.logo_url}}<img class="crest" src="{{ school.logo_url }}" alt="">{{/if}}
    <div class="band-text">
      <div class="school-name">{{ school.name | upper }}</div>
      <div class="school-line">{{ school.address }}</div>
    </div>
  </div>

  <div class="body">
    <div class="photo-frame">
      {{#if holder.photo_url}}
      <img class="photo" src="{{ holder.photo_url }}" alt="">
      {{else}}
      <div class="photo-missing">PHOTO</div>
      {{/if}}
    </div>

    <div class="details">
      <div class="holder-name">{{ holder.name | upper }}</div>
      {{#if holder.class}}<div class="holder-role">{{ holder.class }} {{ holder.arm }}</div>{{/if}}
      {{#if holder.designation}}<div class="holder-role">{{ holder.designation }}</div>{{/if}}
      <table class="fields">
        <tr><th>{{ holder.id_label }}</th><td>{{ holder.id_value | default:"—" }}</td></tr>
        <tr><th>Session</th><td>{{ card.session | default:"—" }}</td></tr>
        <tr><th>Valid to</th><td>{{ card.expires_on | date:"M Y" | default:"until revoked" }}</td></tr>
      </table>
    </div>

    <div class="qr-frame">
      {{#if verification.qr_code_url}}
      <img class="qr" src="{{ verification.qr_code_url }}" alt="">
      {{/if}}
      <div class="serial">{{ card.serial }}</div>
    </div>
  </div>
</div>
```

### Back

Note the disclaimer. It is there for the reason given in §1, and a school
replacing this design should keep something equivalent.

```html
<div class="card back">
  <div class="back-title">{{ school.name | upper }}</div>

  <div class="conditions">
    <p>This card remains the property of the school and must be returned on request.</p>
    <p>It confirms identity only. It is not a key, a payment card, or an access pass.</p>
    <p>If found, please return it to the school or call the number below.</p>
  </div>

  <table class="back-fields">
    <tr><th>Holder</th><td>{{ holder.name }}</td></tr>
    <tr><th>{{ holder.id_label }}</th><td>{{ holder.id_value | default:"—" }}</td></tr>
    {{#if holder.blood_group}}<tr><th>Blood group</th><td>{{ holder.blood_group }}</td></tr>{{/if}}
    <tr><th>Issued</th><td>{{ card.issued_on | date:"j M Y" }}</td></tr>
    <tr><th>Serial</th><td>{{ card.serial }}</td></tr>
  </table>

  <div class="back-foot">
    <div class="contact">
      <div>{{ school.phone }}</div>
      <div>{{ school.email }}</div>
    </div>
    <div class="verify">
      {{#if verification.qr_code_url}}<img class="qr-small" src="{{ verification.qr_code_url }}" alt="">{{/if}}
      <div class="verify-note">Scan to check this card is current</div>
    </div>
  </div>
</div>
```

---

## 12. Endpoints

| Method | Path | Who |
|---|---|---|
| `GET` | `/api/v1/id-cards/template-contract` | admin |
| `GET` | `/api/v1/id-cards/templates` | admin |
| `POST` | `/api/v1/id-cards/templates` | admin |
| `GET` | `/api/v1/id-cards/templates/{id}` | admin |
| `GET` | `/api/v1/id-cards/templates/{id}/preview` | admin — add `?view=sheet` to preview the imposed sheet |
| `POST` | `/api/v1/id-cards/templates/{id}/activate` | admin |
| `DELETE` | `/api/v1/id-cards/templates/{id}` | admin |
| `POST` | `/api/v1/id-cards/preflight` | admin |
| `GET`/`POST` | `/api/v1/id-cards/print-runs` | admin |
| `GET` | `/api/v1/id-cards/print-runs/{id}` | admin |
| `GET` | `/api/v1/id-cards/print-runs/{id}/download` | admin |
| `GET` | `/api/v1/id-cards/holders/{type}/{id}` | admin |
| `POST` | `/api/v1/id-cards/{id}/revoke` | admin |
| `POST` | `/api/v1/id-cards/{id}/replace` | admin |
| `GET` | `/api/v1/verify-id/{token}` | **public**, throttled |

Everything except the last requires `school_admin` or `super_admin`. A teacher
can see a class roster but has no business minting the identity documents for
it, and the print-run endpoints hand back a PDF of every child's face in a
class.
