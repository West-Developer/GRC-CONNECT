import { Clock } from "lucide-react"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { enrollmentDeadlineNotice } from "@/features/lib/enrollment-deadline"

/**
 * What a newly created Transferee or Returnee sees on every page they can reach (ADR 0048, amended by
 * ADR 0050): the account is deactivated when the deadline passes unless their enrollment is finished and
 * paid, and the other portal options appear only once they are enrolled and have paid.
 */
export function EnrollmentDeadlineBanner({
  deadlineAt,
  now = new Date(),
}: {
  deadlineAt: string
  now?: Date
}) {
  const { countdown, dateLabel } = enrollmentDeadlineNotice(deadlineAt, now)

  return (
    <Alert className="portal-storage-alert">
      <Clock aria-hidden="true" />
      <AlertDescription>
        <strong>
          This account will be deactivated {countdown} ({dateLabel})
        </strong>{" "}
        unless your enrollment is completed and paid. The other options unlock
        once you are enrolled and have paid.
      </AlertDescription>
    </Alert>
  )
}
