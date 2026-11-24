import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { jobsEligibleAssignees } from "@erp/core-api";
import { RemoteLookupCombobox } from "../../components/ui/remote-lookup-combobox";
import { Button } from "../../components/ui/button";
import { shouldRetryQuery } from "../../lib/api-errors";

export function JobAssigneeCombobox({
  jobId,
  actorId,
  value,
  onChange,
  disabled,
}: {
  jobId: number;
  actorId: number;
  value: { id: number; name: string } | null;
  onChange: (value: { id: number; name: string }) => void;
  disabled?: boolean;
}) {
  const [search, setSearch] = useState("");
  const [settled, setSettled] = useState("");
  const [page, setPage] = useState(1);
  useEffect(() => {
    const timer = setTimeout(() => setSettled(search.trim()), 250);
    return () => clearTimeout(timer);
  }, [search]);
  const enabled = settled.length >= 2 && search.trim().length >= 2 && !disabled;
  const query = useQuery({
    queryKey: ["jobs-assignees", actorId, jobId, settled, page],
    enabled,
    queryFn: () =>
      jobsEligibleAssignees(jobId, { search: settled, page, per_page: 20 }),
    retry: shouldRetryQuery,
    staleTime: 0,
    gcTime: 0,
  });
  const data =
    enabled && query.data?.status === 200 ? query.data.data : undefined;
  const waiting = search.trim() !== settled;
  return (
    <div className="space-y-2 min-w-0">
      <RemoteLookupCombobox
        id="job-assignee"
        label="Responsible employee"
        className="w-full"
        value={value ? String(value.id) : ""}
        selectedLabel={value?.name}
        disabled={disabled}
        placeholder="Search eligible employees"
        searchPlaceholder="Type at least 2 name characters…"
        search={search}
        onSearchChange={(text) => {
          setSearch(text);
          setPage(1);
        }}
        searching={waiting || (enabled && query.isFetching)}
        loading={enabled && query.isFetching}
        loadError={
          enabled && query.isError
            ? {
                noun: "eligible employees",
                error: query.error,
                onRetry: () => void query.refetch(),
              }
            : undefined
        }
        options={
          enabled && !waiting
            ? (data?.data ?? []).map((person) => ({
                value: String(person.id),
                label: `${person.name} · ${person.id}`,
              }))
            : []
        }
        onValueChange={(id) => {
          const person = data?.data.find((p) => String(p.id) === id);
          if (person) onChange(person);
        }}
        footer={
          enabled && data ? (
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border p-2 text-sm">
              <Button
                type="button"
                variant="outline"
                disabled={page <= 1 || query.isFetching}
                onClick={() => setPage((p) => p - 1)}
              >
                Previous employees
              </Button>
              <span>
                Page {data.meta.current_page} of {data.meta.last_page}
              </span>
              <Button
                type="button"
                variant="outline"
                disabled={page >= data.meta.last_page || query.isFetching}
                onClick={() => setPage((p) => p + 1)}
              >
                More employees
              </Button>
            </div>
          ) : undefined
        }
      />
      <p className="text-sm text-muted-foreground">
        Search by name. Only currently eligible employees are offered;
        responsibility grants no access.
      </p>
    </div>
  );
}
