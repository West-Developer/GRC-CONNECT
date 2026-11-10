import { Badge } from "@/features/components/ui/badge"
import type { StudentProfile } from "@/features/schemas/admission-schema"

/**
 * Where a student's account stands for Admission: set up, waiting for the payment that releases the
 * setup email, or waiting for the student to open it. A Transferee or Returnee whose 7 days ran out
 * is back at "waiting for setup" (the overdue command deactivated the account, ADR 0048), so it says
 * the deadline passed: sending the setup email again is what admits them again.
 */
export function InvitationStatusBadge({
  profile,
  now = new Date(),
}: {
  profile: Pick<
    StudentProfile,
    | "account_setup_status"
    | "invitation_delivery_status"
    | "enrollment_deadline_at"
  >
  now?: Date
}) {
  if (profile.account_setup_status === "active") {
    return <Badge variant="success">Activated</Badge>
  }
  // A freshman pays the Cashier first; the setup email follows the first payment.
  if (profile.account_setup_status === "awaiting_payment") {
    return <Badge variant="warning">Awaiting payment</Badge>
  }
  if (
    profile.enrollment_deadline_at !== null &&
    new Date(profile.enrollment_deadline_at).getTime() <= now.getTime()
  ) {
    return <Badge variant="destructive">Deadline passed</Badge>
  }
  if (profile.invitation_delivery_status === "failed") {
    return <Badge variant="destructive">Delivery failed</Badge>
  }
  if (profile.invitation_delivery_status === "sent") {
    return <Badge variant="warning">Awaiting setup</Badge>
  }
  return <Badge variant="outline">Pending delivery</Badge>
}
