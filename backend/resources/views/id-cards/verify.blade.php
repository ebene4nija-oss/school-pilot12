{{--
    The page a phone camera lands on after scanning an ID card.

    Written for the actual reader: a gateman standing in the sun, holding
    someone else's card, on a cheap Android phone, possibly on 3G. So —

      * the verdict is the first thing on the page and is legible at arm's
        length, in colour and in words, because colour alone fails for a
        colour-blind reader and in direct sunlight;
      * there is no JavaScript, no web font and no external request of any
        kind, so it renders on the first byte and works on a bad connection;
      * the photo is inline, because the check that catches a forged card is
        a human comparing a face to a face.

    Everything here is escaped by Blade. The values come from our own
    database, not from a school-supplied design, so this is ordinary Blade
    rather than the restricted dialect the printed card is rendered with.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $card['found'] ? 'ID card check' : 'Unrecognised card' }}</title>
<style>
  :root {
    --ink: #12141a;
    --muted: #5b6472;
    --line: #dfe4ea;
    --ok: #0b6b4f;
    --ok-bg: #e7f6ef;
    --bad: #a4232b;
    --bad-bg: #fdecec;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    padding: 16px;
    background: #f2f4f7;
    color: var(--ink);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    line-height: 1.45;
  }
  .sheet { max-width: 26rem; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(16,24,40,.12); }

  /* The verdict. Large, worded, and colour-coded — in that order of
     importance, so it still reads if the colour is lost. */
  .verdict { padding: 18px 20px; }
  .verdict.ok { background: var(--ok-bg); border-bottom: 3px solid var(--ok); }
  .verdict.bad { background: var(--bad-bg); border-bottom: 3px solid var(--bad); }
  .verdict h1 { margin: 0; font-size: 1.5rem; letter-spacing: -0.01em; }
  .verdict.ok h1 { color: var(--ok); }
  .verdict.bad h1 { color: var(--bad); }
  .verdict p { margin: 6px 0 0; color: var(--muted); font-size: .95rem; }

  .holder { display: flex; gap: 16px; padding: 20px; border-bottom: 1px solid var(--line); }
  .holder img { width: 84px; height: 106px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); flex: none; }
  .holder .no-photo { width: 84px; height: 106px; border: 1px dashed var(--line); border-radius: 6px; color: var(--muted); font-size: .7rem; display: flex; align-items: center; justify-content: center; text-align: center; flex: none; }
  .holder h2 { margin: 0; font-size: 1.2rem; }
  .holder .role { color: var(--muted); margin: 2px 0 0; }
  .holder .school { margin: 10px 0 0; font-weight: 600; font-size: .9rem; }

  dl { margin: 0; padding: 16px 20px; }
  .row { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; font-size: .9rem; }
  .row dt { color: var(--muted); }
  .row dd { margin: 0; font-weight: 600; text-align: right; }

  .note { padding: 14px 20px; background: #fafbfc; border-top: 1px solid var(--line); color: var(--muted); font-size: .78rem; }
  .note strong { color: var(--ink); }
  .stamp { text-align: center; color: var(--muted); font-size: .72rem; margin: 12px 0 0; }
</style>
</head>
<body>
<main class="sheet">
  <div class="verdict {{ $card['valid'] ? 'ok' : 'bad' }}">
    <h1>{{ $card['headline'] }}</h1>
    <p>{{ $card['detail'] }}</p>
  </div>

  @if ($card['found'])
    <div class="holder">
      @if (!empty($card['photo']))
        <img src="{{ $card['photo'] }}" alt="Photograph of the card holder">
      @else
        <div class="no-photo">No photo<br>on file</div>
      @endif
      <div>
        <h2>{{ $card['holder_name'] !== '' ? $card['holder_name'] : 'Name not on record' }}</h2>
        @if ($card['role'] !== '')
          <p class="role">{{ $card['role'] }}</p>
        @endif
        <p class="school">{{ $card['school'] }}</p>
      </div>
    </div>

    <dl>
      <div class="row"><dt>Card serial</dt><dd>{{ $card['serial'] }}</dd></div>
      @if (!empty($card['issued_on']))
        <div class="row"><dt>Issued</dt><dd>{{ \Illuminate\Support\Carbon::parse($card['issued_on'])->format('j M Y') }}</dd></div>
      @endif
      <div class="row">
        <dt>Valid until</dt>
        {{-- A staff card usually has no end date. Saying so in words beats an
             empty field, which reads as missing data rather than as a fact. --}}
        <dd>{{ !empty($card['expires_on']) ? \Illuminate\Support\Carbon::parse($card['expires_on'])->format('j M Y') : 'Until withdrawn' }}</dd>
      </div>
    </dl>
  @endif

  {{--
      The disclaimer is not boilerplate. Doc §3 rules out access-control
      hardware on purpose, and the failure mode of a convincing "VALID" page
      is a school starting to treat the card as a key anyway. Saying what the
      check does and does not prove, on the page that does the checking, is
      what keeps that from happening.
  --}}
  <p class="note">
    <strong>This confirms the card, not the person.</strong>
    It shows that the school issued this card and that it is still current.
    Check that the face above matches the person in front of you. This card is
    not a key, a pass, or a payment card.
  </p>
</main>

<p class="stamp">Checked {{ $card['checked_at'] ?? '' }} · SchoolPilot</p>
</body>
</html>
