# SchoolPilot — To do

Items parked for later. Each needs a decision before work starts.

## Payments (done 2026-10-09)

- [x] School payment gateway keys screen: Administration > Online payments.
- [x] `recordPayment` capped at the invoice balance, locked, duplicate cash
      within 10 minutes asks for confirmation.
- [x] Receipts go to the guardians of record, never a placeholder number.
- [x] Only pending cash / bank transfers are approved or rejected by hand;
      card payments are confirmed by the gateway webhook. One locked,
      idempotent settlement path (PaymentSettlement) for all three.
- [x] The webhook settles only when the gateway's amount and currency match
      the payment; a mismatch stays pending and is audited.

## AI providers (added 2026-10-06)

- [x] Verify OpenAI and Gemini prices: checked against the official pricing
      pages on 2026-10-08; all prices now live in `App\Support\AiModels`.
- [x] Default models updated: Claude Haiku 4.5 / Sonnet 5.5, GPT-5 mini /
      GPT-6.1 Sol, Gemini 3.1 Flash-Lite / 3.8 Flash. Switch from
      Super Admin > AI service.
- [x] NDPA: Anthropic, OpenAI and Google named in the parent consent text,
      the data policy and the trust page; remark prompts no longer send the
      child's name.
- [ ] First live call with real keys: use the **Test** button on each provider
      card in Super Admin > AI service (tests only fake the providers).
- [ ] Re-check prices when they change: Gemini 3.8 Flash rises on 2027-01-01
      (already in the list); GPT-5.6 Sol promo ends 2026-11-21 (not offered).
- [ ] **AI cross-border consent is recorded but not enforced.** Parents can
      refuse it on the Safety page, but the tutor and AI remarks do not check
      it yet. Decide what refusal should block (the tutor for that child; a
      remark draft for that child) before switching enforcement on, since
      schools with no consent records would otherwise lose AI entirely.
