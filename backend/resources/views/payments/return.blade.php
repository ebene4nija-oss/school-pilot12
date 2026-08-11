{{--
    The page a payer lands on when checkout finishes.

    Says nothing about whether the payment succeeded, because it cannot know:
    it is unauthenticated, and the only thing that decides a payment is the
    gateway's signed webhook. Claiming success here would be a lie roughly one
    time in every failed card attempt.

    Self-contained on purpose — no stylesheet, no font, no script. It renders
    inside an app webview on a handset that has just been on a bank's 3-D Secure
    page over a metered connection, and it is the last thing standing between a
    parent and their receipt.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment complete — SchoolPilot</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            box-sizing: border-box;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f6f7f9;
            color: #000e22;
        }
        .card {
            max-width: 26rem;
            width: 100%;
            background: #fff;
            border: 1px solid #d7dce3;
            border-radius: 12px;
            padding: 28px 24px;
            text-align: center;
        }
        .mark {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #002447;
            color: #fff;
            font-size: 28px;
            line-height: 56px;
        }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        p { margin: 0 0 12px; line-height: 1.5; color: #46505e; }
        .quiet { font-size: 0.875rem; color: #6b7580; margin-bottom: 0; }
        @media (prefers-color-scheme: dark) {
            body { background: #000e22; color: #eef1f6; }
            .card { background: #071c33; border-color: #1b3a5f; }
            .mark { background: #718cb5; color: #000e22; }
            p { color: #b9c4d4; }
            .quiet { color: #8f9cad; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="mark">&checkmark;</div>
        <h1>Checkout complete</h1>
        <p>
            Your school is confirming the payment with its bank. This usually
            takes a few seconds.
        </p>
        <p class="quiet">
            You can close this window. Your receipt appears under School fees
            once the confirmation arrives.
        </p>
    </div>
</body>
</html>
