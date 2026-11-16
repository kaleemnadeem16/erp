import type { JobSummaryResource } from "@erp/core-api";
import type { DataColumn } from "../../components/data-table/types";
import { StatusBadge, type StatusTone } from "../../components/ui/status-badge";
import { formatDateTime } from "../../lib/format-date";

export const jobStates = ["RELEASED", "ACTIVE", "COMPLETED", "CLOSED"] as const;
export function JobStatus({ state }: { state: string }) {
  const tones: Record<string, StatusTone> = {
    RELEASED: "pending",
    ACTIVE: "active",
    COMPLETED: "success",
    CLOSED: "success",
  };
  return (
    <StatusBadge tone={tones[state] ?? "draft"}>
      {state.charAt(0) + state.slice(1).toLowerCase()}
    </StatusBadge>
  );
}
export function responsibleLabel(
  job: Pick<JobSummaryResource, "responsible_member">,
): string {
  return job.responsible_member
    ? (job.responsible_member.name ?? `Employee #${job.responsible_member.id}`)
    : "Unassigned";
}
export const jobColumns: DataColumn<JobSummaryResource>[] = [
  {
    key: "number",
    header: "Job number",
    value: (r) => r.number,
    sortable: true,
    cardRole: "title",
  },
  {
    key: "description",
    header: "Description",
    value: (r) => r.description,
    cardRole: "subtitle",
  },
  {
    key: "state",
    header: "Status",
    value: (r) => r.state,
    cell: (r) => <JobStatus state={r.state} />,
    sortable: true,
  },
  {
    key: "responsible",
    header: "Responsible employee",
    value: responsibleLabel,
  },
  {
    key: "has_open_hold",
    header: "Open hold",
    value: (r) => (r.has_open_hold ? "Yes" : "No"),
  },
  {
    key: "created_at",
    header: "Created",
    value: (r) => formatDateTime(r.created_at),
    sortable: true,
  },
  {
    key: "released_at",
    header: "Released",
    value: (r) => formatDateTime(r.released_at),
    sortable: true,
    screenVisible: false,
  },
  {
    key: "started_at",
    header: "Started",
    value: (r) => formatDateTime(r.started_at),
    sortable: true,
    screenVisible: false,
  },
];
