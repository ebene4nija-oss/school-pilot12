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

- [ ] Verify OpenAI and Gemini prices in `AiSpendLedger` / `config/services.php`
      against each provider's pricing page.
- [ ] Confirm the default model names (`gpt-5-mini`, `gpt-5`, `gemini-2.5-flash`,
      `gemini-2.5-pro`) are still current.
- [ ] NDPA: name OpenAI and Google as processors in the privacy notice and the
      AI cross-border consent text before any school switches provider.
- [ ] First live call with a real OpenAI and Gemini key (tests only fake them).
