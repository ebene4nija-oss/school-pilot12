# SchoolPilot — To do

Items parked for later. Each needs a decision before work starts.

## Payments (needs approval — payment config, see CLAUDE.md)

- [ ] **School payment gateway keys screen.** Let a school admin enter its own
      Paystack / Flutterwave keys from the web (write-only, encrypted, audited),
      so fees settle to the school's account. Explain the change and its risk
      before building.
- [ ] **`recordPayment` has no balance cap.** A manual payment can exceed what
      the invoice owes.
- [ ] **WhatsApp receipt goes to a placeholder number** (+2348000000000) instead
      of the guardian's.
- [ ] **Reconcile can approve card payments.** Only offline payments (transfer,
      cash) should be approvable by hand; card payments are confirmed by the
      gateway webhook.

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
