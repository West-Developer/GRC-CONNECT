import { cn } from "@/features/lib/utils"
import {
  ENROLLMENT_GROUP_PRESENTATION,
  ENROLLMENT_GROUPS,
  formatCount,
  formatShare,
  groupTotal,
} from "@/features/lib/enrollment-status-groups"
import type { EnrollmentStatusGroupCounts } from "@/features/schemas/dashboard-schema"

/**
 * One student population split into the four enrollment groups as a single
 * 100% bar. Thin, with a 2px surface gap between segments (no borders) and a
 * minimum width so a 0.4% slice is still visible. The values are also spelled
 * out in the accessible name, so the bar is never the only way to read them.
 */
export function EnrollmentGroupBar({
  groups,
  className,
}: {
  groups: EnrollmentStatusGroupCounts
  className?: string
}) {
  const total = groupTotal(groups)
  const summary = ENROLLMENT_GROUPS.map(
    (group) =>
      `${ENROLLMENT_GROUP_PRESENTATION[group].label} ${formatCount(groups[group])} (${formatShare(groups[group], total)})`,
  ).join(", ")

  return (
    <div
      role="img"
      aria-label={
        total === 0
          ? "No students"
          : `${formatCount(total)} students: ${summary}`
      }
      className={cn(
        "flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full bg-muted",
        className,
      )}
    >
      {ENROLLMENT_GROUPS.filter((group) => groups[group] > 0).map((group) => (
        <span
          key={group}
          className="h-full rounded-full"
          style={{
            flex: `${groups[group]} 1 0`,
            minWidth: 3,
            backgroundColor: ENROLLMENT_GROUP_PRESENTATION[group].color,
          }}
        />
      ))}
    </div>
  )
}
