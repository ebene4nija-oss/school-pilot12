/**
 * The "SchoolPilot compared" table, edited in the console (Super Admin >
 * Compare). Only competitors the operator published with a verification date
 * arrive here. With none, the page is hidden from navigation and the sitemap.
 */
import generated from "@/generated/comparison.json";

export type Comparison = {
  rows: { label: string; schoolpilot: string }[];
  competitors: { name: string; website: string | null; verified_on: string; values: string[] }[];
  updated_at: string | null;
};

export const comparison = generated as unknown as Comparison | null;

export const hasComparison = comparison !== null && comparison.competitors.length > 0 && comparison.rows.length > 0;
