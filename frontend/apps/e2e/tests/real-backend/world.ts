import { readFileSync } from "node:fs";

/** One throwaway person of the test world: a real Sanctum token and the duties the world builder granted. */
export interface WorldUser {
  id: number;
  name: string;
  company: number;
  duties: string[];
  /** Only the revoked officer: the duties a stale screen still believes it holds. */
  stale_screen_duties?: string[];
  token: string;
}
export interface Scenario {
  tag: string;
  on_site: boolean;
  acceptance: number;
  case: number;
  contact: number;
  contact2: number;
  site: number;
  /** Present once the world builder prepared the handover. */
  plan?: number;
  stage?: number;
  agreement_evidence?: number;
  superseded_evidence?: number;
  current_evidence?: number;
  /** Present once the world builder released it. */
  job?: number;
  job_code?: string;
}
export interface World {
  users: Record<"officer" | "revoked_officer" | "preparer" | "viewer" | "releaser_without_jobs" | "officer_without_job_view" | "evidence_recorder" | "foreign_officer", WorldUser>;
  scenarios: Record<"remote" | "onsite" | "evidence" | "bare" | "stale" | "race" | "dup" | "norecv" | "released", Scenario>;
  decoys: { foreign_contact: number; foreign_site: number };
  /** The reusable Jobs fixture: a Job released through the real Sales release, with its Jobs-capable holders. */
  jobs: { released_job: number; released_job_code: string; job_holders: string[] };
  duties: { sales: string[]; jobs: string[] };
}

export const backendUrl = (): string => process.env.E2E_REAL_BACKEND_URL ?? "";
let cached: World | null = null;
/** The world the harness built for THIS run (a fresh database every run). */
export function world(): World {
  cached ??= JSON.parse(readFileSync(process.env.E2E_REAL_BACKEND_WORLD ?? "", "utf8")) as World;
  return cached;
}
