// Compile-time contract for the quotation draft write endpoints (QL1b-2). It is never imported or run: `tsc` checking it is the test. It fails if the
// OpenAPI source stops typing the create body, the line/header edit body, the replay metadata or the required Idempotency-Key header, so a regeneration
// that "matches the commit" cannot quietly turn them back into untyped options.
import { quotationsStore, quotationsUpdate } from "../generated/quotation/quotation";
import type { StoreQuotationDraftRequest } from "../generated/models/storeQuotationDraftRequest";

export async function quotationDraftWriteContract(): Promise<void> {
  const create: StoreQuotationDraftRequest = {
    recipient_party_id: 1,
    currency_id: 1,
    pricing_date: "2026-10-27",
    valid_through_local_date: "2099-01-01",
    opportunity_id: null,
  };
  const created = await quotationsStore(create, { "Idempotency-Key": "create-1" });
  if (created.status === 201 || created.status === 200) {
    const revision: number = created.data.data.revision_id;
    const generation: number = created.data.data.input_generation;
    const replayed: boolean = created.data.data.replayed;
    void [revision, generation, replayed];
  }

  const updated = await quotationsUpdate(
    1,
    {
      expected_generation: 3,
      valid_through_local_date: "2099-01-01",
      add_lines: [{ source_kind: "MANUAL", description: "Survey", manual_measure: "job", offered_quantity: "1", unit_price: null }],
      update_lines: [{ id: 5, offered_quantity: "2.5000", unit_price: "10.0000" }],
      remove_lines: [7],
    },
    { "Idempotency-Key": "update-1" },
  );
  if (updated.status === 409) {
    const code: string = updated.data.reason_code;
    void code;
  }

  // @ts-expect-error the Idempotency-Key header is required on create
  await quotationsStore(create, {});
  // @ts-expect-error the Idempotency-Key header is required on update
  await quotationsUpdate(1, { expected_generation: 3 }, {});
  // @ts-expect-error expected_generation is required on update
  await quotationsUpdate(1, { remove_lines: [7] }, { "Idempotency-Key": "update-2" });
  // @ts-expect-error a quantity is an exact decimal written as text, never a number
  await quotationsUpdate(1, { expected_generation: 3, update_lines: [{ id: 5, offered_quantity: 2.5 }] }, { "Idempotency-Key": "update-3" });
  // @ts-expect-error a field the draft does not have is not part of the body
  await quotationsStore({ state: "ISSUED" }, { "Idempotency-Key": "create-2" });
}
