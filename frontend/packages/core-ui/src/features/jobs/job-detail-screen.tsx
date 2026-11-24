import { useEffect, useState } from "react";
import { useParams } from "react-router";
import { useJobsShow, useJobsHistory } from "@erp/core-api";
import { RecordPresenter } from "../../components/page/record-presenter";
import type { Presentation } from "../../lib/presentation";
import { PermissionRoute, AccessDenied } from "../../routes/permission-route";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { DetailField } from "../../components/detail/detail-field";
import { Skeleton } from "../../components/ui/skeleton";
import { Button } from "../../components/ui/button";
import { ListError } from "../../components/data-table/list-error";
import { formatDateTime } from "../../lib/format-date";
import { isApiError } from "../../lib/api-errors";
import { JobOperationActions } from "./job-operation-actions";
import { JobStatus, responsibleLabel } from "./job-columns";

export function JobDetailScreen({
  presentation = "sheet",
}: {
  presentation?: Presentation;
}) {
  return (
    <PermissionRoute permission="job.view">
      <JobDetailContent presentation={presentation} />
    </PermissionRoute>
  );
}
function JobDetailContent({ presentation }: { presentation: Presentation }) {
  const { id } = useParams();
  const jobId = Number(id);
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [jobId]);
  const detail = useJobsShow(jobId);
  const history = useJobsHistory(jobId, { page, per_page: 25 });
  const job = detail.data?.status === 200 ? detail.data.data.data : undefined;
  const events = history.data?.status === 200 ? history.data.data : undefined;
  const denied = isApiError(detail.error) && detail.error.status === 403;
  return (
    <RecordPresenter
      presentation={presentation}
      title={job?.number ?? "Job details"}
      listPath="/jobs"
      listLabel="Jobs"
      actions={
        job ? (
          <JobOperationActions
            jobId={jobId}
            state={job.state}
            held={job.has_open_hold}
          />
        ) : undefined
      }
    >
      {detail.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : denied ? (
        <AccessDenied />
      ) : detail.isError || !job ? (
        <ListError
          message="Couldn’t load this Job."
          error={detail.error}
          onRetry={() => void detail.refetch()}
        />
      ) : (
        <>
          <DetailPageLayout>
            <DetailSection title="Overview">
              <DetailField label="Status">
                <JobStatus state={job.state} />
              </DetailField>
              <DetailField label="Responsible employee">
                {responsibleLabel(job)}
              </DetailField>
              <DetailField label="Created">
                {formatDateTime(job.created_at)}
              </DetailField>
              <DetailField label="Released">
                {formatDateTime(job.released_at)}
              </DetailField>
              <DetailField label="Started">
                {formatDateTime(job.started_at)}
              </DetailField>
              <DetailField label="Default activity">
                {job.default_activity?.description ?? "Not recorded"}
              </DetailField>
            </DetailSection>
            <DetailSection title="Original agreed work" fullWidth>
              <DetailField label="Scope" fullWidth>
                <span className="whitespace-pre-wrap break-words">
                  {job.baseline?.scope_description ?? "Not recorded"}
                </span>
              </DetailField>
              <DetailField label="Deliverable" fullWidth>
                <span className="whitespace-pre-wrap break-words">
                  {job.baseline?.deliverable ?? "Not recorded"}
                </span>
              </DetailField>
              <DetailField label="Agreed conditions" fullWidth>
                <span className="whitespace-pre-wrap break-words">
                  {job.baseline?.agreed_conditions ?? "Not recorded"}
                </span>
              </DetailField>
              <DetailField label="Agreed work version">
                {job.baseline?.version}
              </DetailField>
              <DetailField label="Recorded">
                {formatDateTime(job.baseline?.recorded_at)}
              </DetailField>
            </DetailSection>
            <DetailSection title="Assignment">
              <DetailField label="Employee">
                {job.assignment
                  ? (job.assignment.member_name ?? "Employee unavailable")
                  : "Unassigned"}
              </DetailField>
              <DetailField label="Employee ID">
                {job.assignment?.member_id}
              </DetailField>
              <DetailField label="Assignment recorded">
                {formatDateTime(job.assignment?.recorded_at)}
              </DetailField>
              <DetailField label="Intended from">
                {formatDateTime(job.assignment?.intended_from)}
              </DetailField>
              <DetailField label="Intended until">
                {formatDateTime(job.assignment?.intended_until)}
              </DetailField>
            </DetailSection>
            <DetailSection title="Open holds" fullWidth>
              {job.open_holds.length === 0 ? (
                <p>No open holds.</p>
              ) : (
                job.open_holds.map((h) => (
                  <div key={h.id} className="space-y-2 break-words">
                    <p>{h.reason}</p>
                    <p className="text-sm text-muted-foreground">
                      Effective from {formatDateTime(h.effective_from)} ·
                      Recorded {formatDateTime(h.recorded_at)}
                    </p>
                  </div>
                ))
              )}
            </DetailSection>
            {(["completion", "closeout"] as const).map((key) => {
              const evidence = job[key];
              return (
                <DetailSection
                  key={key}
                  title={
                    key === "completion" ? "Completion" : "Operational closeout"
                  }
                >
                  {evidence ? (
                    <>
                      <DetailField
                        label={
                          key === "completion"
                            ? "Completion recorded"
                            : "Closeout recorded"
                        }
                      >
                        {formatDateTime(evidence.recorded_at)}
                      </DetailField>
                      <DetailField label="Statement" fullWidth>
                        <span className="whitespace-pre-wrap break-words">
                          {evidence.statement ?? "No statement recorded"}
                        </span>
                      </DetailField>
                      {evidence.claimed_occurred_at && (
                        <DetailField
                          label={`Claimed occurrence (${evidence.occurrence_precision})`}
                        >
                          {formatDateTime(evidence.claimed_occurred_at)}
                        </DetailField>
                      )}
                    </>
                  ) : (
                    <p>
                      {key === "completion"
                        ? "No completion recorded."
                        : "No closeout recorded."}
                    </p>
                  )}
                </DetailSection>
              );
            })}
          </DetailPageLayout>
          <section aria-labelledby="job-history-heading" className="space-y-4">
            <h2 id="job-history-heading" className="text-lg font-semibold">
              Job history
            </h2>
            <p className="text-sm text-muted-foreground">
              Ordered by recorded sequence. Claimed occurrence times are shown
              separately.
            </p>
            {history.isLoading ? (
              <Skeleton className="h-32 w-full" />
            ) : history.isError ? (
              <ListError
                message="Couldn’t load Job history."
                error={history.error}
                onRetry={() => void history.refetch()}
              />
            ) : (
              <>
                <ol className="space-y-4" aria-label="Job timeline">
                  {events?.data.map((e) => (
                    <li
                      key={e.id}
                      className="border-l-2 border-border pl-4 space-y-1 break-words"
                    >
                      <p className="font-medium">
                        {(
                          {
                            RELEASE: "Released",
                            ASSIGN: "Responsibility assigned",
                            START: "Started",
                            HOLD: "Hold placed",
                            RESOLVE_HOLD: "Hold resolved",
                            COMPLETE: "Completed",
                            CLOSE: "Closed",
                          } as Record<string, string>
                        )[e.operation] ?? e.operation}
                      </p>
                      <p className="text-sm">
                        Recorded {formatDateTime(e.recorded_at)} ·{" "}
                        {e.actor.name ?? "Employee unavailable"}
                      </p>
                      <p className="text-sm text-muted-foreground">
                        {e.from_state ?? "New"} → {e.to_state}
                      </p>
                      {e.responsible_member_id !== null && (
                        <p className="text-sm">
                          Assigned employee ID: {e.responsible_member_id}
                        </p>
                      )}
                      {e.hold_id !== null && (
                        <p className="text-sm">Hold reference: {e.hold_id}</p>
                      )}
                      {e.reason && (
                        <p className="whitespace-pre-wrap">{e.reason}</p>
                      )}
                      {e.claimed_occurred_at && (
                        <p className="text-sm text-muted-foreground">
                          Claimed occurrence ({e.occurrence_precision}):{" "}
                          {formatDateTime(e.claimed_occurred_at)}
                        </p>
                      )}
                    </li>
                  ))}
                </ol>
                {events?.data.length === 0 && <p>No history recorded.</p>}
                <nav
                  aria-label="Job history pages"
                  className="flex flex-wrap items-center gap-3"
                >
                  <Button
                    variant="outline"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => p - 1)}
                  >
                    Previous history page
                  </Button>
                  <span>
                    Page {page} of {events?.meta.last_page ?? 1}
                  </span>
                  <Button
                    variant="outline"
                    disabled={page >= (events?.meta.last_page ?? 1)}
                    onClick={() => setPage((p) => p + 1)}
                  >
                    Next history page
                  </Button>
                </nav>
              </>
            )}
          </section>
        </>
      )}
    </RecordPresenter>
  );
}
