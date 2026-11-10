"use client"

import { useState } from "react"
import { toast } from "sonner"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import {
  DataTable,
  type DataTableColumn,
} from "@/features/components/portal/data-table"
import { InvitationStatusBadge } from "@/features/components/portal/invitation-status-badge"
import { Paginator } from "@/features/components/portal/paginator"
import { ResendQueueLinkButton } from "@/features/components/portal/resend-queue-link-button"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Input } from "@/features/components/ui/input"
import { useDebouncedValue } from "@/features/hooks/use-debounced-value"
import {
  useResendAccountSetupInvitationMutation,
  useStudentDirectoryQuery,
} from "@/features/hooks/use-student-records"
import type {
  StudentProfile,
  StudentProfileFilters,
} from "@/features/schemas/admission-schema"

type AccountFilter = NonNullable<StudentProfileFilters["account_setup_status"]>
type Filter = AccountFilter | "all"

const FILTERS: readonly { value: Filter; label: string }[] = [
  { value: "all", label: "All freshmen" },
  { value: "awaiting_payment", label: "Awaiting payment" },
  { value: "pending", label: "Paid · account not set up" },
  { value: "active", label: "Account set up" },
]

/** Table cells do not wrap by default; these hold sentences, so they may, or the table outgrows a laptop screen. */
const WRAPPING_CELL = "whitespace-normal"

const STAGE_LABELS = {
  awaiting_payment: "Waiting for the Cashier",
  awaiting_section: "Paid · waiting for a section",
  enrolling: "Paid · enrolling",
} as const

/** The institution's wall-clock time, not the viewer's browser timezone (same rule as every other date here). */
function formatPaidAt(value: string): string {
  return new Intl.DateTimeFormat("en-US", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(new Date(value))
}

/**
 * Sends the account setup email again. It only exists for a freshman who has paid and not yet set the
 * account up (the server refuses anything else). A send that reaches the mail server but fails comes back
 * as a normal answer with `invitation_delivery_status: "failed"`, so that is reported as a failure here,
 * not as a success.
 */
function ResendSetupEmailButton({ profile }: { profile: StudentProfile }) {
  const resend = useResendAccountSetupInvitationMutation()

  return (
    <Button
      type="button"
      size="sm"
      variant="secondary"
      disabled={resend.isPending}
      onClick={() =>
        void resend
          .mutateAsync(profile.id)
          .then((updated) => {
            if (updated.invitation_delivery_status === "failed") {
              toast.error(
                `The setup email could not be sent to ${updated.email}. Check the address, then try again.`,
              )
              return
            }
            toast.success(`Setup email sent to ${updated.email}.`)
          })
          .catch(() => {
            toast.error("Failed to resend the setup email.")
          })
      }
    >
      {resend.isPending ? "Sending…" : "Resend setup email"}
    </Button>
  )
}

function PaymentCell({ profile }: { profile: StudentProfile }) {
  if (profile.freshman_payment_confirmed_at === null) {
    return <Badge variant="warning">Not paid yet</Badge>
  }

  return (
    <div className="flex flex-col items-start gap-1">
      <Badge variant="success">Paid</Badge>
      <span className="text-xs text-muted-foreground">
        {formatPaidAt(profile.freshman_payment_confirmed_at)}
      </span>
    </div>
  )
}

function ActionCell({ profile }: { profile: StudentProfile }) {
  if (profile.account_setup_status === "pending") {
    return <ResendSetupEmailButton profile={profile} />
  }
  if (profile.account_setup_status === "active") {
    return (
      <span className="text-xs whitespace-normal text-muted-foreground">
        Account already set up.
      </span>
    )
  }

  // Awaiting payment: the setup email follows the first payment, so the queue link is what can be sent.
  return (
    <div className="flex flex-col items-start gap-1">
      {profile.queue_ticket_number !== null && (
        <ResendQueueLinkButton
          profile={profile}
          label="Resend queue link"
          size="sm"
          variant="secondary"
        />
      )}
      <span className="max-w-60 text-xs whitespace-normal text-muted-foreground">
        The setup email is sent after the Cashier confirms the payment.
      </span>
    </div>
  )
}

/**
 * Admission's view of the freshmen they processed (2026-10-16): has each one paid, where are they, and did
 * the account setup email get out. A student's mailbox is often full, so the email can go missing; once the
 * student has freed space and the first payment is confirmed, Admission resends it from here. Every row is
 * the same student record the Student Directory shows, narrowed to freshmen.
 */
export function AdmissionFreshmenPanel({
  onOpenRecord,
}: {
  onOpenRecord: (profile: StudentProfile) => void
}) {
  const [filter, setFilter] = useState<Filter>("all")
  const [search, setSearch] = useState("")
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebouncedValue(search, 300)

  const query = useStudentDirectoryQuery({
    student_type: "freshman",
    // Not the imported past students: only freshmen who took a queue number or paid, newest first.
    intake: "pay_first",
    ...(filter === "all" ? {} : { account_setup_status: filter }),
    ...(debouncedSearch.trim() ? { search: debouncedSearch.trim() } : {}),
    page,
    per_page: 20,
  })

  const columns: DataTableColumn<StudentProfile>[] = [
    {
      key: "student",
      header: "Student",
      cellClassName: WRAPPING_CELL,
      render: (profile) => (
        <div>
          <button
            type="button"
            className="text-left font-medium text-primary underline-offset-4 hover:underline"
            onClick={() => onOpenRecord(profile)}
          >
            {profile.name}
          </button>
          <div className="whitespace-normal text-xs text-muted-foreground">
            {profile.student_number} · {profile.email}
          </div>
        </div>
      ),
    },
    {
      key: "ticket",
      header: "Queue no.",
      render: (profile) => profile.queue_ticket_number ?? "—",
    },
    {
      key: "payment",
      header: "Payment",
      cellClassName: WRAPPING_CELL,
      render: (profile) => <PaymentCell profile={profile} />,
    },
    {
      key: "stage",
      header: "Stage",
      cellClassName: WRAPPING_CELL,
      render: (profile) =>
        profile.freshman_status === null
          ? "—"
          : STAGE_LABELS[profile.freshman_status],
    },
    {
      key: "account",
      header: "Setup email",
      render: (profile) => <InvitationStatusBadge profile={profile} />,
    },
    {
      key: "action",
      header: "Action",
      cellClassName: WRAPPING_CELL,
      render: (profile) => <ActionCell profile={profile} />,
    },
  ]

  return (
    <Card>
      <CardHeader>
        <CardTitle level={2}>Freshmen</CardTitle>
        <CardDescription>
          See which freshmen have paid the Cashier and whether their account
          setup email went out. If a student did not receive it (for example
          their mailbox is full), ask them to free some space, then use Resend
          setup email once the payment shows as Paid.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          {FILTERS.map((option) => (
            <Button
              key={option.value}
              type="button"
              size="sm"
              variant={filter === option.value ? "default" : "outline"}
              aria-pressed={filter === option.value}
              onClick={() => {
                setFilter(option.value)
                setPage(1)
              }}
            >
              {option.label}
            </Button>
          ))}
        </div>
        <div className="w-full max-w-sm">
          <Input
            type="search"
            placeholder="Name, student number, or email"
            aria-label="Search freshmen"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
        </div>
        <AsyncBoundary
          query={{ ...query, data: query.data?.data }}
          isEmpty={(rows) => rows.length === 0}
          emptyMessage="No freshmen match this list."
          loadingLabel="Loading the freshmen…"
        >
          {(rows) => (
            <DataTable
              caption="Freshmen"
              columns={columns}
              rows={rows}
              rowKey={(profile) => profile.id}
            />
          )}
        </AsyncBoundary>
        <Paginator
          currentPage={query.data?.meta.current_page ?? 1}
          lastPage={query.data?.meta.last_page ?? 1}
          onPageChange={setPage}
        />
      </CardContent>
    </Card>
  )
}
