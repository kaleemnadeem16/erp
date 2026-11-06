import { forwardRef, useState } from "react";
import { useInfiniteQuery } from "@tanstack/react-query";
import { RemoteLookupCombobox } from "../../components/ui/remote-lookup-combobox";
import { Button } from "../../components/ui/button";
import { lookupState, shouldRetryQuery } from "../../lib/api-errors";
import { useDebouncedSearch } from "../../lib/use-debounced-search";
import type {
  AccessCandidate,
  AccessCandidateQuery,
  FamilyAccessGateway,
} from "./quotation-family-access-contract";

type Props = {
  familyId: number;
  gateway: FamilyAccessGateway;
  kind: AccessCandidateQuery["kind"];
  level?: "VIEW" | "PREPARE";
  value: AccessCandidate | null;
  onChange: (value: AccessCandidate) => void;
  label: string;
  disabled?: boolean;
  id?: string;
  "aria-describedby"?: string;
  "aria-labelledby"?: string;
  "aria-invalid"?: boolean;
};

/** Search and cursor-pagination share one lookup; a known selected label never depends on its results page. */
export const FamilyAccessLookup = forwardRef<HTMLButtonElement, Props>(
  function FamilyAccessLookup(
    {
      familyId,
      gateway,
      kind,
      level,
      value,
      onChange,
      label,
      disabled,
      ...field
    },
    ref,
  ) {
    const [committed, setCommitted] = useState<string | undefined>();
    const search = useDebouncedSearch(committed, setCommitted);
    const query = useInfiniteQuery({
      queryKey: [
        "quotation-access-candidates",
        familyId,
        kind,
        level,
        committed,
      ],
      queryFn: ({ pageParam }) =>
        gateway.candidates(familyId, {
          kind,
          level,
          search: committed ?? "",
          cursor: pageParam || null,
        }),
      initialPageParam: "",
      getNextPageParam: (last) => last.meta.next_cursor ?? undefined,
      retry: shouldRetryQuery,
    });
    const rows = Array.from(
      new Map(
        (query.data?.pages.flatMap((page) => page.data) ?? []).map((row) => [
          row.id,
          row,
        ]),
      ).values(),
    );
    return (
      <RemoteLookupCombobox
        {...field}
        ref={ref}
        label={label}
        className="w-full"
        value={value ? String(value.id) : ""}
        selectedLabel={value ? `${value.name} · #${value.id}` : undefined}
        onValueChange={(id) => {
          const chosen = rows.find((row) => String(row.id) === id);
          if (chosen) onChange(chosen);
        }}
        options={rows.map((row) => ({
          value: String(row.id),
          label: `${row.name} · #${row.id}`,
        }))}
        search={search.input}
        onSearchChange={search.onValueChange}
        onSearchKeyDown={search.searchProps.onKeyDown}
        searching={search.input !== (committed ?? "")}
        disabled={disabled}
        {...lookupState(query, kind === "unit" ? "units" : "people")}
        footer={
          query.hasNextPage && search.input === (committed ?? "") ? (
            <Button
              type="button"
              variant="ghost"
              className="min-h-11 w-full"
              disabled={query.isFetchingNextPage}
              onKeyDown={(event) => {
                if (event.key === "Enter" || event.key === " ")
                  event.stopPropagation();
              }}
              onClick={() => void query.fetchNextPage()}
            >
              {query.isFetchingNextPage ? "Loading more…" : "Load more matches"}
            </Button>
          ) : null
        }
      />
    );
  },
);
