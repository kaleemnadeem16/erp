import { Badge } from "../../components/ui/badge";

export function MemberStatusBadge({ status }: { status: string }) {
  const variant =
    status === "ACTIVE" ? "success" : status === "INVITED" ? "info" : "warning";

  return (
    <Badge variant={variant}>
      {status.charAt(0) + status.slice(1).toLowerCase()}
    </Badge>
  );
}
