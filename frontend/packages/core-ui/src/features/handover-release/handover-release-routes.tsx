import { useParams } from "react-router";
import { HandoverReleaseScreen } from "./handover-release-screen";

/** A malformed plan id never reaches a screen or the network. */
function planFrom(raw: string | undefined): number | null {
  return raw && /^[1-9]\d*$/.test(raw) && Number.isSafeInteger(Number(raw))
    ? Number(raw)
    : null;
}

export function HandoverReleaseRoute() {
  const id = planFrom(useParams().plan);
  return id === null ? (
    <div className="p-4 md:p-6" role="status">
      <h1 className="text-lg font-semibold">Handover unavailable</h1>
      <p className="text-sm">This handover is not available.</p>
    </div>
  ) : (
    <HandoverReleaseScreen planId={id} />
  );
}
