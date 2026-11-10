const DAY_MS = 24 * 60 * 60 * 1000
const MANILA_TIME_ZONE = "Asia/Manila"

/**
 * Where a restricted student lands: Enrollment, where credit mapping, subject selection, payment and the
 * queue number all live (ADR 0048).
 */
export const RESTRICTED_STUDENT_MODULE_ID = "enrollment"

/**
 * The modules a newly created Transferee or Returnee can open until they are enrolled and have paid
 * (ADR 0048, amended by ADR 0050): Enrollment, and Grades, which holds their Prospectus and the credited
 * grades that decide whether they enroll as Regular or Irregular.
 */
export const RESTRICTED_STUDENT_MODULE_IDS = ["enrollment", "grades"] as const

/** Whether a restricted student may open this portal page (`/portal/<module>`, with any sub-path). */
export function isPathOpenWhileRestricted(pathname: string): boolean {
  const [, portal, moduleId] = pathname.split("/")

  return (
    portal === "portal" &&
    (RESTRICTED_STUDENT_MODULE_IDS as readonly string[]).includes(
      moduleId ?? "",
    )
  )
}

/**
 * While the student profile carries an enrollment deadline the student is not enrolled and paid yet
 * (the deadline ends when the Cashier confirms the payment that enrolls them, ADR 0050). Accounts that
 * existed before the rule, and every other student type, have none, and an unknown profile (still
 * loading, or failed) never restricts anyone.
 */
export function isPortalRestricted(
  profile: { enrollment_deadline_at: string | null } | null | undefined,
): boolean {
  return profile?.enrollment_deadline_at != null
}

export interface EnrollmentDeadlineNotice {
  /** Whole days left, a partial day counted as a day; 0 once the deadline has passed. */
  daysLeft: number
  /** "in 7 days", "in 1 day" or "today". */
  countdown: string
  /** The deadline's calendar date in the school's time zone, e.g. "October 21, 2026". */
  dateLabel: string
}

export function enrollmentDeadlineNotice(
  deadlineAt: string,
  now: Date,
): EnrollmentDeadlineNotice {
  const deadline = new Date(deadlineAt)
  const daysLeft = Math.max(
    0,
    Math.ceil((deadline.getTime() - now.getTime()) / DAY_MS),
  )

  return {
    daysLeft,
    countdown:
      daysLeft === 0
        ? "today"
        : `in ${daysLeft} ${daysLeft === 1 ? "day" : "days"}`,
    dateLabel: new Intl.DateTimeFormat("en-US", {
      timeZone: MANILA_TIME_ZONE,
      year: "numeric",
      month: "long",
      day: "numeric",
    }).format(deadline),
  }
}
