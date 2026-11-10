"use client"

import Link from "next/link"

import { useAuth } from "@/features/auth/use-auth"
import { useProgramChairReviewCountsQuery } from "@/features/hooks/use-program-chair-review-counts"
import { cn } from "@/features/lib/utils"

export type ProgramChairEnrollmentTab =
  "enrollment" | "irregular-advising" | "credit-mappings"

const TABS: readonly {
  id: ProgramChairEnrollmentTab
  label: string
  href: string
}[] = [
  {
    id: "enrollment",
    label: "Enrollment",
    href: "/portal/program-chair-enrollment",
  },
  {
    id: "irregular-advising",
    label: "Irregular Advising",
    href: "/portal/irregular-enrollments",
  },
  {
    id: "credit-mappings",
    label: "Credit Mappings",
    href: "/portal/credit-mappings",
  },
]

/** The number shown above a tab; a count above 99 reads "99+". */
function formatCount(count: number): string {
  return count > 99 ? "99+" : String(count)
}

/**
 * The Program Chair's Enrollment page has three parts: the term's enrollment steps, the irregular students'
 * advising, and the credit mappings of Transferees and Returnees. They are separate pages (so every link and
 * notification that points at one still works), joined by this bar. A number above Irregular Advising and Credit
 * Mappings says how many are waiting for the Chair; it disappears at zero.
 */
export function ProgramChairEnrollmentTabs({
  current,
}: {
  current: ProgramChairEnrollmentTab
}) {
  const { session } = useAuth()
  const countsQuery = useProgramChairReviewCountsQuery()

  if (session?.role !== "program_chair") return null

  const counts: Record<ProgramChairEnrollmentTab, number> = {
    enrollment: 0,
    "irregular-advising": countsQuery.data?.irregular_advising ?? 0,
    "credit-mappings": countsQuery.data?.credit_mappings ?? 0,
  }

  return (
    <nav aria-label="Enrollment sections" className="pt-2">
      <ul className="flex flex-wrap items-center gap-2">
        {TABS.map((tab) => {
          const isCurrent = tab.id === current
          const count = counts[tab.id]

          return (
            <li key={tab.id} className="relative">
              <Link
                href={tab.href}
                aria-current={isCurrent ? "page" : undefined}
                className={cn(
                  "inline-flex min-h-9 items-center rounded-md border px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-1 focus-visible:outline-ring",
                  isCurrent
                    ? "border-transparent bg-primary text-primary-foreground"
                    : "bg-background text-foreground/70 hover:text-foreground",
                )}
              >
                {tab.label}
                {count > 0 && (
                  <span className="sr-only">
                    , {count} waiting for your review
                  </span>
                )}
              </Link>
              {count > 0 && (
                <span
                  aria-hidden="true"
                  data-testid={`tab-count-${tab.id}`}
                  className="pointer-events-none absolute -top-2 -right-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-xs font-semibold text-white"
                >
                  {formatCount(count)}
                </span>
              )}
            </li>
          )
        })}
      </ul>
    </nav>
  )
}
