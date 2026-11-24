import { useParams } from "react-router";
import { HandoverPreparationScreen } from "./handover-preparation-screen";

/** A malformed acceptance id never reaches a screen or the network. */
function acceptanceFrom(raw: string | undefined): number | null {
  return raw && /^[1-9]\d*$/.test(raw) && Number.isSafeInteger(Number(raw))
    ? Number(raw)
    : null;
}

export function HandoverPreparationRoute() {
  const id = acceptanceFrom(useParams().acceptance);
  return id === null ? (
    <div className="p-4 md:p-6" role="status">
      <h1 className="text-lg font-semibold">Service unavailable</h1>
      <p className="text-sm">This accepted service is not available.</p>
    </div>
  ) : (
    <HandoverPreparationScreen acceptanceId={id} />
  );
}
