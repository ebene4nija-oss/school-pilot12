// Prices approved 2026-10-08. They match the rates in Super Admin > Billing
// (backend services.billing.*_rate_kobo); change both together.
export const RESULTS = 500;
export const SUBSCRIPTION = 300;
export const BOARDING = 1000;

export const naira = (n: number) => `₦${n.toLocaleString("en-NG")}`;
