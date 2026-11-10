import { act, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { AccountingPaymentWorkspace } from "@/features/components/portal/accounting-payment-workspace"
import { playQueueAlert } from "@/features/lib/queue-announcement"
import { renderWithSession } from "@/tests/render-app"

// The alert chime and voice use Web Audio / Speech Synthesis, which jsdom
// does not have; the tests only care that the Cashier's buttons ask for it.
vi.mock("@/features/lib/queue-announcement", () => ({
  playQueueAlert: vi.fn(),
}))

const paginationLinks = {
  first: "https://api.test/queue-tickets?page=1",
  last: "https://api.test/queue-tickets?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const servingTicket = {
  type: "queue_ticket",
  id: 1,
  enrollment_id: 9,
  student_number: "2026-0001",
  student_id: 4,
  is_freshman: false,
  ticket_number: "Q001",
  queue_date: "2026-07-30",
  status: "serving",
  status_label: "Serving",
  priority: "regular",
  priority_label: "Regular",
  created_at: "2026-07-30T00:05:00Z",
  served_at: null,
  requeued_at: null,
} as const

const waitingTicket = {
  ...servingTicket,
  id: 2,
  enrollment_id: 10,
  student_number: "2026-0002",
  student_id: 5,
  ticket_number: "Q002",
  status: "waiting",
  status_label: "Waiting",
  created_at: "2026-07-30T00:10:00Z",
} as const

const assessmentItems = [
  {
    id: 101,
    category: "tuition",
    category_label: "Tuition",
    label: "Tuition",
    quantity: "10.50",
    unit_amount: "200.00",
    amount: "2100.00",
  },
  {
    id: 102,
    category: "miscellaneous",
    category_label: "Miscellaneous",
    label: "Registration",
    quantity: null,
    unit_amount: null,
    amount: "200.00",
  },
] as const

const pendingPaymentEnrollment = {
  type: "enrollment",
  id: 9,
  student_id: 4,
  student_number: "2026-0001",
  student_name: null,
  student_year_level: null,
  student_financial_status: null,
  student_financial_status_label: null,
  academic_term_id: 2,
  status: "pending_payment",
  status_label: "Pending Payment",
  total_units: 10.5,
  requires_overload_approval: false,
  program_head_comment: null,
  submitted_at: "2026-07-30T00:00:00Z",
  program_head_decided_at: null,
  registrar_decided_at: "2026-07-30T00:00:00Z",
  payment_confirmed_at: null,
  enrolled_at: null,
  subjects: [],
  queue_ticket: null,
  assessment: {
    total_amount: "5775.00",
    currency: "PHP",
    assessed_at: "2026-07-30T00:00:00Z",
    items: assessmentItems,
  },
} as const

const studentAccount = {
  type: "student_account",
  student_id: 4,
  student_name: "Maria Santos",
  student_number: "2026-0001",
  year_level: 3,
  currency: "PHP",
  total_assessed: "9275.00",
  total_paid: "1000.00",
  prior_balance: "3500.00",
  outstanding_balance: "8275.00",
  advance_payment_balance: "0.00",
  has_promissory_note_on_file: true,
  entries: [
    {
      enrollment_id: 3,
      academic_term_id: 1,
      academic_term_label: "2025-2026 · 2nd",
      assessment_amount: "4500.00",
      confirmed_payment_amount: "1000.00",
      account_payment_amount: "0.00",
      outstanding_balance: "3500.00",
      promissory_note_on_file: true,
    },
    {
      enrollment_id: 9,
      academic_term_id: 2,
      academic_term_label: "2026-2027 · 1st",
      assessment_amount: "5775.00",
      confirmed_payment_amount: "0.00",
      account_payment_amount: "0.00",
      outstanding_balance: "5775.00",
      promissory_note_on_file: false,
    },
  ],
  transactions: [],
} as const

/** A student whose only bill is the term being paid: no previous bill (owner, 2026-10-13: no promissory note). */
const studentAccountNoPriorBill = {
  ...studentAccount,
  total_assessed: "5775.00",
  total_paid: "0.00",
  prior_balance: "0.00",
  outstanding_balance: "5775.00",
  has_promissory_note_on_file: false,
  entries: [studentAccount.entries[1]],
} as const

/** What the API says when no full-payment discount applies (the quote's default in these tests). */
const noDiscountQuote = {
  type: "payment_quote",
  total_amount: "5775.00",
  full_payment: {
    applies: false,
    percentage: null,
    discount_amount: null,
    net_total: "5775.00",
    reason: "off",
  },
} as const

const discountQuote = {
  type: "payment_quote",
  total_amount: "5775.00",
  full_payment: {
    applies: true,
    percentage: "10.0",
    discount_amount: "577.50",
    net_total: "5197.50",
    reason: null,
  },
} as const

const cashierPaymentCandidate = {
  type: "cashier_payment_candidate",
  student_id: 5,
  student_name: "Juan Dela Cruz",
  student_number: "2026-0002",
  year_level: 3,
  enrollment_id: 10,
  ticket: {
    id: 2,
    ticket_number: "Q002",
    status: "waiting",
  },
} as const

const cashierPaymentCandidateNoTicket = {
  ...cashierPaymentCandidate,
  ticket: null,
} as const

const openCycle = {
  type: "queue_cycle",
  id: 1,
  opened_on: "2026-07-30",
  status: "open",
  status_label: "Open",
  cut_off_at: null,
  cut_off_service_date: null,
} as const

const cutOffCycle = {
  ...openCycle,
  status: "cut_off",
  status_label: "Cut off for today",
  cut_off_at: "2026-07-30T09:00:00Z",
  cut_off_service_date: "2026-07-30",
} as const

const accountingSession = {
  userId: "6",
  displayName: "Accounting Staff",
  role: "accounting_staff",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

function mockRoutes(
  overrides: {
    tickets?: readonly unknown[]
    candidate?: typeof cashierPaymentCandidate | typeof cashierPaymentCandidateNoTicket
    cycle?: typeof openCycle | typeof cutOffCycle | null
    /** The quote's `data`, or the string "fail" for a response that cannot be read. */
    quote?: unknown
    /** The student's account; defaults to one with a previous bill. */
    account?: typeof studentAccount | typeof studentAccountNoPriorBill
  } = {},
) {
  return (input: RequestInfo | URL, init?: RequestInit) => {
    const target = url(input)
    if (target.includes("/cashier-payment-candidates"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: overrides.candidate ?? cashierPaymentCandidate,
          }),
        ),
      )
    if (target.includes("/queue-cycle/cut-off"))
      return Promise.resolve(
        new Response(JSON.stringify({ data: overrides.cycle ?? cutOffCycle })),
      )
    if (target.includes("/queue-cycle/resume"))
      return Promise.resolve(
        new Response(JSON.stringify({ data: overrides.cycle ?? openCycle })),
      )
    if (target.includes("/queue-cycle"))
      return Promise.resolve(
        new Response(JSON.stringify({ data: overrides.cycle ?? null })),
      )
    if (target.includes("/queue-tickets") && init?.method === "POST")
      return Promise.resolve(
        new Response(JSON.stringify({ data: waitingTicket }), { status: 201 }),
      )
    if (target.includes("/queue-tickets"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: overrides.tickets ?? [servingTicket, waitingTicket],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    if (target.includes("/students/4/account"))
      return Promise.resolve(
        new Response(JSON.stringify({ data: overrides.account ?? studentAccount })),
      )
    if (target.includes("/enrollments/9/payment-quote"))
      return Promise.resolve(
        overrides.quote === "fail"
          ? new Response(
              JSON.stringify({ error: { code: "NOT_FOUND", message: "Not found.", request_id: "r1" } }),
              { status: 404 },
            )
          : new Response(JSON.stringify({ data: overrides.quote ?? noDiscountQuote })),
      )
    if (target.includes("/enrollments/9/assessment") && init?.method === "PATCH")
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: {
              ...pendingPaymentEnrollment,
              assessment: {
                ...pendingPaymentEnrollment.assessment,
                total_amount: "2925.00",
                items: [
                  { ...assessmentItems[0], unit_amount: "250.00", amount: "2625.00" },
                  assessmentItems[1],
                ],
              },
            },
          }),
        ),
      )
    if (target.includes("/enrollments") && init?.method === "POST")
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: {
              enrollment: { ...pendingPaymentEnrollment, status: "enrolled" },
              payment: {
                external_reference: null,
                amount: "5775.00",
                promissory_note_on_file: false,
                confirmed_at: "2026-07-30T00:00:00Z",
              },
              document: {
                document_type: "cor",
                document_number: "COR000009",
                generated_at: "2026-07-30T00:00:00Z",
              },
            },
          }),
          { status: 201 },
        ),
      )
    if (target.includes("/enrollments"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: new URL(target).searchParams.get("without_queue_ticket") === "1"
              && (overrides.tickets ?? [servingTicket, waitingTicket]).some((ticket) => (ticket as { enrollment_id?: number }).enrollment_id === pendingPaymentEnrollment.id)
              ? [] : [pendingPaymentEnrollment],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  }
}

// Confirm payment now starts with the Payee/Scholar question (ADR 0025).
async function continueAsRegularPayee(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole("button", { name: "Confirm payment" }))
  const classification = await screen.findByRole("dialog", {
    name: "Payment classification",
  })
  await user.click(within(classification).getByRole("radio", { name: /Regular payee/ }))
  await user.click(within(classification).getByRole("button", { name: "Continue" }))
}

/** The pending-payment enrollment as the API returns it with a scholarship applied. */
function enrollmentWithScholarship(percentage: number | null, base = 5775) {
  const discount = percentage === null ? 0 : Math.round(base * percentage) / 100
  return {
    ...pendingPaymentEnrollment,
    student_financial_status: percentage === null ? null : "scholar",
    student_financial_status_label: percentage === null ? null : "Scholar",
    assessment: {
      ...pendingPaymentEnrollment.assessment,
      total_amount: (base - discount).toFixed(2),
      items:
        percentage === null
          ? assessmentItems
          : [
              ...assessmentItems,
              {
                id: 103,
                category: "scholarship_discount",
                category_label: "Scholarship discount",
                label: `Scholarship discount (${percentage}%)`,
                quantity: `${percentage}.0`,
                unit_amount: null,
                amount: `-${discount.toFixed(2)}`,
              },
            ],
    },
  }
}

/**
 * Routes with a stateful scholarship: PUT applies a tier, DELETE removes it,
 * and the pending-payment list always reflects the current state, like the API.
 */
function mockScholarshipRoutes(
  calls: string[],
  initial: number | null = null,
  base = 5775,
  routeOverrides: Parameters<typeof mockRoutes>[0] = {},
) {
  let percentage = initial

  return (input: RequestInfo | URL, init?: RequestInit) => {
    const target = url(input)

    if (target.includes("/enrollments/9/scholarship-discount")) {
      if (init?.method === "PUT") {
        percentage = (JSON.parse(init.body as string) as { percentage: number }).percentage
        calls.push(`PUT ${percentage}`)
      } else {
        percentage = null
        calls.push("DELETE")
      }
      return Promise.resolve(
        new Response(JSON.stringify({ data: enrollmentWithScholarship(percentage, base) })),
      )
    }
    if (
      target.includes("/enrollments") &&
      !target.includes("/payment") &&
      (init?.method ?? "GET") === "GET"
    ) {
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [enrollmentWithScholarship(percentage, base)],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    }

    return mockRoutes(routeOverrides)(input, init)
  }
}

/** Type the amount received into the payment modal (it opens at 0.00). */
async function typeAmount(
  user: ReturnType<typeof userEvent.setup>,
  dialog: HTMLElement,
  value: string,
) {
  const amount = within(dialog).getByLabelText("Amount")
  await user.clear(amount)
  await user.type(amount, value)
}

describe("AccountingPaymentWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it("does not render the queue for an unauthorized role", () => {
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })

  it("shows Registrar-approved students before they claim a ticket and issues one only on request", async () => {
    fetchMock.mockImplementation(mockRoutes({ tickets: [] }))
    const user = userEvent.setup()
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    const table = await screen.findByRole("table", { name: "Approved for payment" })
    expect(within(table).getByText("2026-0001")).toBeInTheDocument()
    expect(screen.queryByText("Finding eligible payment ticket…")).not.toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === "POST")).toBe(false)
    await user.click(within(table).getByRole("button", { name: "Issue queue ticket" }))
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/queue-tickets"),
      expect.objectContaining({ method: "POST", body: JSON.stringify({ student_number: "2026-0001" }) }),
    )
  })

  it("pages approved students and the waiting line while Call next keeps the first ticket", async () => {
    const fallback = mockRoutes({ tickets: [] })
    const laterTicket = { ...waitingTicket, id: 22, ticket_number: "Q022", student_number: "2026-0022" }
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      const params = new URL(target).searchParams
      const page = Number(params.get("page") ?? 1)
      if (target.includes("/queue-tickets") && init?.method === "GET" && params.get("status") === "waiting") {
        return Promise.resolve(new Response(JSON.stringify({ data: page === 1 ? [waitingTicket] : [laterTicket], links: paginationLinks, meta: { ...paginationMeta, current_page: page, last_page: 2, total: 21 } })))
      }
      if (target.includes("/enrollments?") && params.get("without_queue_ticket") === "1") {
        return Promise.resolve(new Response(JSON.stringify({ data: [{ ...pendingPaymentEnrollment, student_number: page === 1 ? "2026-0001" : "2026-0099" }], links: paginationLinks, meta: { ...paginationMeta, current_page: page, last_page: 2, total: 21 } })))
      }
      return fallback(input, init)
    })
    const user = userEvent.setup()
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await user.click(await screen.findByRole("button", { name: "Next approved students page" }))
    await waitFor(() => expect(within(screen.getByRole("table", { name: "Approved for payment" })).getByText("2026-0099")).toBeInTheDocument())
    await user.click(screen.getByRole("button", { name: "Next waiting page" }))
    await waitFor(() => expect(within(screen.getByRole("table", { name: "Waiting" })).getByText("Q022")).toBeInTheDocument())
    await user.click(screen.getByRole("button", { name: "Call next →" }))
    expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining("/queue-tickets/2"), expect.objectContaining({ method: "PATCH", body: JSON.stringify({ action: "serve" }) }))
  })

  it("keeps new waiting and serving tickets visible after a full page of served tickets", async () => {
    const fallback = mockRoutes()
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/queue-tickets") && init?.method === "GET") {
        const status = new URL(target).searchParams.get("status")
        const tickets = status === "serving" ? [servingTicket] : status === "waiting" ? [waitingTicket] : []
        return Promise.resolve(new Response(JSON.stringify({ data: tickets, links: paginationLinks, meta: paginationMeta })))
      }
      return fallback(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    expect(await screen.findByText("Q001")).toBeInTheDocument()
    const waiting = await screen.findByRole("table", { name: "Waiting" })
    expect(within(waiting).getByText("Q002")).toBeInTheDocument()
  })

  it("loads the serving enrollment by its exact id when it is outside the approved list page", async () => {
    const fallback = mockRoutes()
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/enrollments?") && init?.method === "GET") {
        const exactIds = new URL(target).searchParams.getAll("enrollment_ids[]")
        return Promise.resolve(new Response(JSON.stringify({
          data: exactIds.includes("9") ? [pendingPaymentEnrollment] : [],
          links: paginationLinks,
          meta: paginationMeta,
        })))
      }
      return fallback(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await waitFor(() => expect(screen.getByRole("button", { name: "Confirm payment" })).toBeEnabled())
    expect(screen.getByText(/10.5 units/)).toBeInTheDocument()
  })

  it("shows the currently serving ticket with its amount due", async () => {
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    expect(await screen.findByText("Q001")).toBeInTheDocument()
    expect(screen.getByText(/10.5 units/)).toBeInTheDocument()
    expect(screen.getByText(/₱5775.00/)).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Confirm payment" }),
    ).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Skip" })).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Call next →" }),
    ).not.toBeInTheDocument()

    const waitingRow = await screen.findByRole("table", { name: "Waiting" })
    expect(within(waitingRow).getByText("Q002")).toBeInTheDocument()
  })



  describe("freshman tickets", () => {
    // A freshman took a number at Admission before any enrollment or account exists.
    const freshmanServing = {
      ...servingTicket,
      id: 7,
      enrollment_id: null,
      student_id: 77,
      student_number: "2026-10-00077",
      ticket_number: "Q007",
      is_freshman: true,
    } as const
    const freshmanWaiting = {
      ...waitingTicket,
      id: 8,
      enrollment_id: null,
      student_id: 78,
      student_number: "2026-10-00078",
      ticket_number: "Q008",
      is_freshman: true,
    } as const

    it("tells the cashier that the student being served is a freshman", async () => {
      fetchMock.mockImplementation(mockRoutes({ tickets: [freshmanServing] }))
      renderWithSession(<AccountingPaymentWorkspace />, {
        session: accountingSession,
      })

      expect(await screen.findByText("Q007")).toBeInTheDocument()
      const nowServing = screen
        .getByRole("heading", { name: "Now serving" })
        .closest<HTMLElement>('[data-slot="card"]')!
      expect(within(nowServing).getByText("Freshman")).toBeInTheDocument()
      expect(within(nowServing).getByText(/2026-10-00077/)).toBeInTheDocument()
    })

    it("does not say that confirming the first payment enrolls the freshman, because only the Registrar's approval does", async () => {
      // Found by the real-browser pass: the serving panel still promised enrollment while the dialog (correctly) said the Registrar approves first.
      fetchMock.mockImplementation(mockRoutes({ tickets: [freshmanServing] }))
      renderWithSession(<AccountingPaymentWorkspace />, {
        session: accountingSession,
      })

      expect(await screen.findByText("Q007")).toBeInTheDocument()
      const nowServing = screen
        .getByRole("heading", { name: "Now serving" })
        .closest<HTMLElement>('[data-slot="card"]')!
      expect(
        within(nowServing).getByText(
          /submits.*to the first section with open seats.*Registrar.*approv/i,
        ),
      ).toBeInTheDocument()
      expect(
        within(nowServing).queryByText(/enrolls the freshman/i),
      ).not.toBeInTheDocument()
    })

    it("marks a waiting freshman in the waiting line", async () => {
      fetchMock.mockImplementation(
        mockRoutes({ tickets: [servingTicket, freshmanWaiting] }),
      )
      renderWithSession(<AccountingPaymentWorkspace />, {
        session: accountingSession,
      })

      const waitingTable = await screen.findByRole("table", { name: "Waiting" })
      const row = within(waitingTable).getByText("Q008").closest("tr") as HTMLElement
      expect(within(row).getByText("Freshman")).toBeInTheDocument()
    })

    it("shows no Freshman badge for a student who claimed at the kiosk", async () => {
      fetchMock.mockImplementation(mockRoutes())
      renderWithSession(<AccountingPaymentWorkspace />, {
        session: accountingSession,
      })

      await screen.findByText("Q001")
      expect(screen.queryByText("Freshman")).not.toBeInTheDocument()
    })

    it("does not offer the ordinary enrollment payment for a freshman who has no enrollment yet", async () => {
      fetchMock.mockImplementation(mockRoutes({ tickets: [freshmanServing] }))
      renderWithSession(<AccountingPaymentWorkspace />, {
        session: accountingSession,
      })

      await screen.findByText("Q007")
      expect(
        screen.queryByRole("button", { name: "Confirm payment" }),
      ).not.toBeInTheDocument()
      expect(
        screen.getByRole("button", { name: "Confirm first payment" }),
      ).toBeEnabled()
    })

    const previewData = {
      type: "freshman_payment_preview",
      student_id: 77,
      student_number: "2026-10-00077",
      student_name: "Juan Dela Cruz",
      ticket_number: "Q007",
      minimum_amount: "1000.00",
      already_confirmed: false,
      can_enroll: true,
      block: {
        block_code: "IT102",
        total_units: 21,
        seats_remaining: 12,
        subjects: [{ code: "ITP1", title: "Programming 1", units: 3 }],
      },
      estimated_total: "9200.00",
    } as const
    const enrolledResult = {
      type: "freshman_payment",
      outcome: "enrolled",
      block_code: "IT102",
      student: { id: 77, student_number: "2026-10-00077", name: "Juan Dela Cruz" },
      enrollment: { id: 90, status: "enrolled", total_units: 21 },
      payment: {
        external_reference: null,
        amount: "1000.00",
        promissory_note_on_file: false,
        confirmed_at: "2026-10-04T01:00:00Z",
      },
      document: {
        id: 5,
        document_type: "cor",
        document_number: "COR000090",
        generated_at: "2026-10-04T01:00:00Z",
      },
      held_amount: null,
    } as const

    function mockFreshmanRoutes({
      preview = previewData,
      result = enrolledResult,
      confirmStatus = 201,
    }: {
      preview?: Record<string, unknown>
      result?: Record<string, unknown>
      confirmStatus?: number
    } = {}) {
      return (input: RequestInfo | URL, init?: RequestInit) => {
        const target = url(input)
        if (target.includes("/freshman-payment-preview"))
          return Promise.resolve(new Response(JSON.stringify({ data: preview })))
        if (target.includes("/freshman-payment") && init?.method === "POST")
          return Promise.resolve(
            confirmStatus === 201
              ? new Response(JSON.stringify({ data: result }), { status: 201 })
              : new Response(
                  JSON.stringify({
                    error: {
                      code: "VALIDATION_FAILED",
                      message: "Validation failed.",
                      errors: { amount: ["A freshman's first payment must be at least PHP 1,000.00."] },
                      request_id: "r1",
                    },
                  }),
                  { status: confirmStatus },
                ),
          )
        return mockRoutes({ tickets: [freshmanServing] })(input, init)
      }
    }

    async function openFreshmanDialog(user: ReturnType<typeof userEvent.setup>) {
      await user.click(
        await screen.findByRole("button", { name: "Confirm first payment" }),
      )
      return screen.findByRole("alertdialog")
    }

    it("shows the section the freshman will get and the estimated assessment before anything is recorded", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(mockFreshmanRoutes())
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      const dialog = await openFreshmanDialog(user)

      expect(await within(dialog).findByText("IT102")).toBeInTheDocument()
      expect(within(dialog).getByText(/12 seats left/)).toBeInTheDocument()
      expect(within(dialog).getByText(/ITP1 — Programming 1/)).toBeInTheDocument()
      expect(within(dialog).getByText(/Estimated assessment/)).toBeInTheDocument()
      expect(within(dialog).getByLabelText("Amount received")).toHaveValue("1000.00")
      // No promissory note for a freshman.
      expect(within(dialog).queryByLabelText(/promissory/i)).not.toBeInTheDocument()
      // Opening the dialog only reads: nothing is recorded until the Cashier confirms.
      expect(
        fetchMock.mock.calls.some(
          ([input, init]) =>
            url(input).includes("/freshman-payment") && init?.method === "POST",
        ),
      ).toBe(false)
    })

    it("confirms the first payment and tells the Cashier which section the freshman is enrolled in", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(mockFreshmanRoutes())
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })
      const dialog = await openFreshmanDialog(user)
      await within(dialog).findByText("IT102")

      await user.clear(within(dialog).getByLabelText("Amount received"))
      await user.type(within(dialog).getByLabelText("Amount received"), "1500")
      await user.type(
        within(dialog).getByLabelText(/Receipt or reference number/),
        "OR-5",
      )
      await user.click(
        within(dialog).getByRole("button", { name: "Confirm first payment" }),
      )

      const notice = await screen.findByRole("status")
      expect(within(notice).getByText(/is enrolled in/)).toBeInTheDocument()
      expect(within(notice).getByText("IT102")).toBeInTheDocument()
      expect(within(notice).getByText(/COR000090/)).toBeInTheDocument()
      expect(within(notice).getByRole("button", { name: "View COR" })).toBeInTheDocument()
      const post = fetchMock.mock.calls.find(
        ([input, init]) => url(input).endsWith("/freshman-payment") && init?.method === "POST",
      )
      expect(JSON.parse(post?.[1]?.body as string)).toEqual({
        amount: "1500.00",
        external_reference: "OR-5",
      })
      await waitFor(() =>
        expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument(),
      )
    })

    it("explains that the payment will be held when every section is full", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(
        mockFreshmanRoutes({
          preview: { ...previewData, can_enroll: false, block: null, estimated_total: null },
          result: {
            ...enrolledResult,
            outcome: "awaiting_section",
            block_code: null,
            enrollment: null,
            payment: null,
            document: null,
            held_amount: "1000.00",
          },
        }),
      )
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })
      const dialog = await openFreshmanDialog(user)

      expect(
        await within(dialog).findByText(/Every section .* is full/i),
      ).toBeInTheDocument()
      // The intro must not promise an automatic enrollment the notice just ruled out.
      await waitFor(() =>
        expect(dialog).not.toHaveTextContent(/enrolls them automatically/i),
      )
      expect(dialog).toHaveTextContent(/No promissory note is needed/i)
      await user.click(
        within(dialog).getByRole("button", { name: "Confirm first payment" }),
      )

      const notice = await screen.findByRole("status")
      expect(within(notice).getByText(/is held/)).toBeInTheDocument()
      expect(within(notice).queryByRole("button", { name: "View COR" })).not.toBeInTheDocument()
    })

    it("will not confirm an amount below the minimum", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(mockFreshmanRoutes())
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })
      const dialog = await openFreshmanDialog(user)
      await within(dialog).findByText("IT102")

      await user.clear(within(dialog).getByLabelText("Amount received"))
      await user.type(within(dialog).getByLabelText("Amount received"), "500")

      expect(
        within(dialog).getByRole("button", { name: "Confirm first payment" }),
      ).toBeDisabled()
      expect(within(dialog).getByText(/Enter an amount of at least/)).toBeInTheDocument()
    })

    const discountedPreview = {
      ...previewData,
      full_payment: {
        applies: true,
        percentage: "10.0",
        discount_amount: "920.00",
        net_total: "8280.00",
        reason: null,
      },
    } as const

    it("opens on Installment, then offers Pay in full at the discounted estimate and sends it as a full payment", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(mockFreshmanRoutes({ preview: discountedPreview }))
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })
      const dialog = await openFreshmanDialog(user)

      const choice = await within(dialog).findByRole("group", { name: "Payment type" })
      // Installment is the default (owner, 2026-10-13): the discount is never applied unless the Cashier picks it.
      expect(within(choice).getByRole("button", { name: /Installment/ })).toHaveAttribute("aria-pressed", "true")
      expect(within(choice).getByRole("button", { name: /Pay in full/ })).toHaveAttribute("aria-pressed", "false")
      expect(within(dialog).getByLabelText("Amount received")).toHaveValue("1000.00")
      expect(within(dialog).queryByText(/Full-payment discount \(10%\)/)).not.toBeInTheDocument()

      await user.click(within(choice).getByRole("button", { name: /Pay in full/ }))
      expect(within(choice).getByRole("button", { name: /Pay in full/ })).toHaveAttribute("aria-pressed", "true")
      expect(within(dialog).getByLabelText("Amount received")).toHaveValue("8280.00")
      expect(within(dialog).getByLabelText("Amount received")).toHaveAttribute("readonly")
      expect(within(dialog).getByText(/Full-payment discount \(10%\)/)).toBeInTheDocument()

      await user.click(within(dialog).getByRole("button", { name: "Confirm first payment" }))

      await screen.findByRole("status")
      const post = fetchMock.mock.calls.find(
        ([input, init]) => url(input).endsWith("/freshman-payment") && init?.method === "POST",
      )
      expect(JSON.parse(post?.[1]?.body as string)).toEqual({
        amount: "8280.00",
        payment_type: "full",
      })
    })

    it("keeps the minimum-payment rule for an installment and sends no payment type", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(mockFreshmanRoutes({ preview: discountedPreview }))
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })
      const dialog = await openFreshmanDialog(user)

      // Installment is already selected: confirming straight away sends the minimum and no payment type.
      expect(await within(dialog).findByRole("button", { name: /Installment/ })).toHaveAttribute("aria-pressed", "true")
      expect(within(dialog).getByLabelText("Amount received")).toHaveValue("1000.00")
      expect(within(dialog).getByLabelText("Amount received")).not.toHaveAttribute("readonly")
      await user.click(within(dialog).getByRole("button", { name: "Confirm first payment" }))

      await screen.findByRole("status")
      const post = fetchMock.mock.calls.find(
        ([input, init]) => url(input).endsWith("/freshman-payment") && init?.method === "POST",
      )
      expect(JSON.parse(post?.[1]?.body as string)).toEqual({ amount: "1000.00" })
    })

    it("shows no payment-type choice when there is nothing to estimate yet", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(
        mockFreshmanRoutes({
          preview: {
            ...previewData,
            can_enroll: false,
            block: null,
            estimated_total: null,
            full_payment: { applies: false, percentage: null, discount_amount: null, net_total: null, reason: "no_estimate" },
          },
        }),
      )
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })
      const dialog = await openFreshmanDialog(user)

      await within(dialog).findByLabelText("Amount received")
      expect(within(dialog).queryByRole("group", { name: "Payment type" })).not.toBeInTheDocument()
    })

    it("shows no payment-type choice once the first payment was already confirmed", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(
        mockFreshmanRoutes({ preview: { ...discountedPreview, already_confirmed: true } }),
      )
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })
      const dialog = await openFreshmanDialog(user)

      // The preview has loaded once the "already confirmed" notice is on screen.
      expect(await within(dialog).findByText(/already confirmed/)).toBeInTheDocument()
      expect(within(dialog).queryByRole("group", { name: "Payment type" })).not.toBeInTheDocument()
      expect(within(dialog).getByLabelText("Amount received")).not.toHaveAttribute("readonly")
    })

    it("keeps the dialog open and shows the server's reason when the confirmation is refused", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(mockFreshmanRoutes({ confirmStatus: 422 }))
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })
      const dialog = await openFreshmanDialog(user)
      await within(dialog).findByText("IT102")

      await user.click(
        within(dialog).getByRole("button", { name: "Confirm first payment" }),
      )

      expect(
        await within(dialog).findByText(/must be at least PHP 1,000\.00/),
      ).toBeInTheDocument()
      expect(screen.getByRole("alertdialog")).toBeInTheDocument()
    })

    it("tells a freshman whose payment is in and who waits for the Registrar that, not that every section is full", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(
        mockFreshmanRoutes({
          result: {
            ...enrolledResult,
            outcome: "awaiting_registrar_approval",
            enrollment: { id: 90, status: "pending_registrar_approval", total_units: 21 },
            payment: null,
            document: null,
            held_amount: "1000.00",
          },
        }),
      )
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })
      const dialog = await openFreshmanDialog(user)
      await within(dialog).findByText("IT102")
      await user.click(
        within(dialog).getByRole("button", { name: "Confirm first payment" }),
      )

      const notice = await screen.findByRole("status")
      expect(notice).toHaveTextContent(/waiting for Registrar approval/i)
      expect(notice).not.toHaveTextContent(/every section is full/i)
      expect(notice).toHaveTextContent(/account setup email was sent/i)
    })
  })

  // A transferee pays first too (ADR 0043, amended 2026-10-05): their number comes from the end of
  // their credit mapping, they already have an account, and they chose their own subjects.
  describe("transferee tickets", () => {
    const transfereeServing = {
      ...servingTicket,
      id: 9,
      enrollment_id: null,
      student_id: 88,
      student_number: "2026-0900",
      ticket_number: "Q009",
      is_freshman: false,
      is_transferee: true,
    } as const
    const transfereeWaiting = {
      ...waitingTicket,
      id: 10,
      enrollment_id: null,
      student_id: 89,
      student_number: "2026-0901",
      ticket_number: "Q010",
      is_freshman: false,
      is_transferee: true,
    } as const
    const transfereePreview = {
      type: "freshman_payment_preview",
      student_id: 88,
      student_number: "2026-0900",
      student_name: "Maria Santos",
      student_type: "transferee",
      ticket_number: "Q009",
      minimum_amount: "1000.00",
      already_confirmed: false,
      can_enroll: true,
      block: null,
      enrollment: {
        id: 31,
        status: "awaiting_first_payment",
        status_label: "Awaiting First Payment",
        total_units: 21,
        subjects: [{ code: "ITP1", title: "Programming 1", units: 3 }],
      },
      estimated_total: "9200.00",
    } as const
    const awaitingRegistrarResult = {
      type: "freshman_payment",
      outcome: "awaiting_registrar_approval",
      block_code: "IT101",
      student: { id: 88, student_number: "2026-0900", name: "Maria Santos" },
      enrollment: { id: 31, status: "pending_registrar_approval", total_units: 21 },
      payment: null,
      document: null,
      held_amount: "1000.00",
    } as const

    function mockTransfereeRoutes({
      preview = transfereePreview,
      result = awaitingRegistrarResult,
      tickets = [transfereeServing],
    }: {
      preview?: Record<string, unknown>
      result?: Record<string, unknown>
      tickets?: readonly unknown[]
    } = {}) {
      return (input: RequestInfo | URL, init?: RequestInit) => {
        const target = url(input)
        if (target.includes("/freshman-payment-preview"))
          return Promise.resolve(new Response(JSON.stringify({ data: preview })))
        if (target.includes("/freshman-payment") && init?.method === "POST")
          return Promise.resolve(
            new Response(JSON.stringify({ data: result }), { status: 201 }),
          )
        return mockRoutes({ tickets: [...tickets] as never })(input, init)
      }
    }

    async function openDialog(user: ReturnType<typeof userEvent.setup>) {
      await user.click(
        await screen.findByRole("button", { name: "Confirm first payment" }),
      )
      return screen.findByRole("alertdialog")
    }

    it("tells the cashier that the student being served is a transferee, and what confirming does", async () => {
      fetchMock.mockImplementation(mockTransfereeRoutes())
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      expect(await screen.findByText("Q009")).toBeInTheDocument()
      const nowServing = screen
        .getByRole("heading", { name: "Now serving" })
        .closest<HTMLElement>('[data-slot="card"]')!
      expect(within(nowServing).getByText("Transferee")).toBeInTheDocument()
      expect(within(nowServing).queryByText("Freshman")).not.toBeInTheDocument()
      // The payment does not enroll them and there is no account setup to send.
      expect(nowServing).toHaveTextContent(/Registrar/)
      expect(nowServing).not.toHaveTextContent(/account setup email/i)
      expect(
        within(nowServing).getByRole("button", { name: "Confirm first payment" }),
      ).toBeEnabled()
    })

    it("marks a waiting transferee in the waiting line", async () => {
      fetchMock.mockImplementation(
        mockTransfereeRoutes({ tickets: [servingTicket, transfereeWaiting] }),
      )
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      const waitingTable = await screen.findByRole("table", { name: "Waiting" })
      const row = within(waitingTable).getByText("Q010").closest("tr") as HTMLElement
      expect(within(row).getByText("Transferee")).toBeInTheDocument()
    })

    it("shows the subjects the transferee chose and the estimated assessment before anything is recorded", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(mockTransfereeRoutes())
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      const dialog = await openDialog(user)

      expect(await within(dialog).findByText(/ITP1 — Programming 1/)).toBeInTheDocument()
      expect(within(dialog).getByText(/Their chosen subjects/i)).toBeInTheDocument()
      expect(within(dialog).getByText(/Estimated assessment/)).toBeInTheDocument()
      expect(dialog).toHaveTextContent(/transferee paying for the first time/i)
      expect(dialog).not.toHaveTextContent(/account setup email/i)
      expect(within(dialog).getByLabelText("Amount received")).toHaveValue("1000.00")
    })

    it("explains that the payment will be held when the transferee has not chosen subjects yet", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(
        mockTransfereeRoutes({
          preview: { ...transfereePreview, can_enroll: false, enrollment: null, estimated_total: null },
        }),
      )
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      const dialog = await openDialog(user)

      expect(
        await within(dialog).findByText(/has not chosen and submitted their subjects/i),
      ).toBeInTheDocument()
      expect(dialog).not.toHaveTextContent(/every section/i)
    })

    it("confirms the first payment and says the enrollment now waits for the Registrar, with no account setup email", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(mockTransfereeRoutes())
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })
      const dialog = await openDialog(user)
      await within(dialog).findByText(/ITP1 — Programming 1/)

      await user.click(
        within(dialog).getByRole("button", { name: "Confirm first payment" }),
      )

      const notice = await screen.findByRole("status")
      expect(notice).toHaveTextContent(/Maria Santos/)
      expect(notice).toHaveTextContent(/waiting for Registrar approval/i)
      expect(notice).not.toHaveTextContent(/account setup email/i)
      expect(notice).not.toHaveTextContent(/every section is full/i)
    })
  })

  it("shows a cut-off banner and lets the cashier resume the queue", async () => {
    // A static `mockRoutes({ cycle })` override applies to every
    // /queue-cycle route uniformly (GET, /cut-off, /resume alike), so it
    // cannot represent a real state transition -- the polled GET refetch
    // after "Resume queue" would keep echoing the same cutOffCycle value
    // forever. Track the transition locally instead, same pattern as
    // "refreshes the waiting list..." above.
    let resumed = false
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/queue-cycle/resume")) {
        resumed = true
        return Promise.resolve(
          new Response(JSON.stringify({ data: openCycle })),
        )
      }
      if (target.includes("/queue-cycle") && !target.includes("cut-off"))
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: resumed ? openCycle : cutOffCycle }),
          ),
        )
      return mockRoutes()(input, init)
    })
    const user = userEvent.setup()
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    expect(
      await screen.findByText(/the queue is cut off for today/i),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Resume queue" }))

    expect(
      await screen.findByRole("button", { name: "Cut off for today" }),
    ).toBeInTheDocument()
  })

  it("lets the cashier cut off the queue for today", async () => {
    // Same reasoning as above, mirrored for the open -> cut-off transition.
    let cutOff = false
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/queue-cycle/cut-off")) {
        cutOff = true
        return Promise.resolve(
          new Response(JSON.stringify({ data: cutOffCycle })),
        )
      }
      if (target.includes("/queue-cycle") && !target.includes("resume"))
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: cutOff ? cutOffCycle : openCycle }),
          ),
        )
      return mockRoutes()(input, init)
    })
    const user = userEvent.setup()
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    await user.click(
      await screen.findByRole("button", { name: "Cut off for today" }),
    )
    await user.click(screen.getByRole("button", { name: "Confirm cut-off" }))

    expect(
      await screen.findByText(/the queue is cut off for today/i),
    ).toBeInTheDocument()
  })

  it("shows an error and keeps the dialog open when cutting off the queue fails", async () => {
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/queue-cycle/cut-off"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "Invalid",
                errors: { cycle: ["No queue is currently open."] },
                request_id: "request-cutoff-fail",
              },
            }),
            { status: 422 },
          ),
        )
      if (target.includes("/queue-cycle") && !target.includes("resume"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: openCycle })),
        )
      return mockRoutes()(input, init)
    })
    const user = userEvent.setup()
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    await user.click(
      await screen.findByRole("button", { name: "Cut off for today" }),
    )
    await user.click(screen.getByRole("button", { name: "Confirm cut-off" }))

    expect(
      await screen.findByText(
        "The queue could not be cut off. Check the connection and try again.",
      ),
    ).toBeInTheDocument()
    // The dialog stays open on failure, matching every other write path in
    // this file (e.g. "Confirm payment"), so the cashier can retry.
    expect(
      screen.getByRole("button", { name: "Confirm cut-off" }),
    ).toBeInTheDocument()
  })

  it("shows an Issue queue ticket button when the candidate has no ticket yet", async () => {
    fetchMock.mockImplementation(
      mockRoutes({ candidate: cashierPaymentCandidateNoTicket }),
    )
    const user = userEvent.setup()
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    await user.type(
      screen.getByLabelText("Find student number"),
      "2026-0002",
    )
    await user.click(screen.getByRole("button", { name: "Find student" }))

    const issueButton = await screen.findByRole("button", {
      name: "Issue queue ticket",
    })
    await user.click(issueButton)

    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/queue-tickets"),
      expect.objectContaining({ method: "POST" }),
    )
  })

  it("sorts a carry-over ticket from an earlier date ahead of a later one", async () => {
    const carryOver = { ...waitingTicket, id: 3, ticket_number: "Q000", queue_date: "2026-07-29", created_at: "2026-07-30T23:00:00Z" }
    fetchMock.mockImplementation(
      mockRoutes({ tickets: [servingTicket, carryOver, waitingTicket] }),
    )
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    const table = await screen.findByRole("table", { name: "Waiting" })
    const rows = await within(table).findAllByRole("row")
    // Q000's queue_date (07-29) is earlier than Q002's (07-30) despite a
    // LATER created_at timestamp -- proving queue_date, not just the
    // COALESCE effective-order, decides the tiebreak.
    expect(within(rows[1]).getByText("Q000")).toBeInTheDocument()
    expect(within(rows[2]).getByText("Q002")).toBeInTheDocument()
  })

  it("shows a serving student's cross-term balance and promissory-note state", async () => {
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    expect(await screen.findByText("Maria Santos")).toBeInTheDocument()
    expect(screen.getByText("Prior balance")).toBeInTheDocument()
    expect(screen.getByText("₱3,500.00")).toBeInTheDocument()
    expect(screen.getByText("Promissory note on file")).toBeInTheDocument()
  })

  it("refreshes the waiting list when a new ticket appears without a page reload", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let ticketIssued = false
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/queue-tickets"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: ticketIssued ? [waitingTicket] : [],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return mockRoutes()(input, init)
    })

    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    const table = await screen.findByRole("table", { name: "Waiting" })
    expect(within(table).queryByText("Q002")).not.toBeInTheDocument()

    ticketIssued = true
    await act(async () => {
      await vi.advanceTimersByTimeAsync(5_000)
    })

    expect(await within(table).findByText("Q002")).toBeInTheDocument()
  })

  it("shows the student's financial status as a badge when set", async () => {
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/enrollments") && init?.method !== "POST")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  ...pendingPaymentEnrollment,
                  student_financial_status: "scholar",
                  student_financial_status_label: "Scholar",
                },
              ],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return mockRoutes()(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    expect(await screen.findByText("Q001")).toBeInTheDocument()
    expect(screen.getByText("Scholar")).toBeInTheDocument()
  })

  it("calls the next waiting ticket when nobody is being served", async () => {
    const user = userEvent.setup()
    let servedRequest: RequestInit | undefined
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/queue-tickets/2") && init?.method === "PATCH") {
        servedRequest = init
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: { ...waitingTicket, status: "serving" } }),
          ),
        )
      }
      return mockRoutes({ tickets: [waitingTicket] })(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    expect(
      await screen.findByText("No one is currently being served."),
    ).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Call next →" }))

    await vi.waitFor(() => expect(servedRequest).toBeDefined())
    expect(JSON.parse(servedRequest?.body as string)).toEqual({
      action: "serve",
    })
  })

  it("opens with the amount at 0.00, and confirms the amount the Cashier types and shows the generated COR", async () => {
    const user = userEvent.setup()
    let paymentRequest: RequestInit | undefined
    fetchMock.mockImplementation((input, init) => {
      if (
        url(input).includes("/enrollments/9/payment") &&
        init?.method === "POST"
      ) {
        paymentRequest = init
      }
      return mockRoutes()(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    await screen.findByText("Q001")
    await continueAsRegularPayee(user)
    const dialog = screen.getByRole("alertdialog")
    // Owner, 2026-10-13: nothing is pre-filled, so a full balance is never confirmed by accident.
    expect(within(dialog).getByLabelText("Amount")).toHaveValue("0.00")
    const confirm = within(dialog).getByRole("button", { name: "Confirm payment" })
    expect(confirm).toBeDisabled()
    // The untouched 0.00 is a prompt, not an error.
    expect(within(dialog).queryByRole("alert")).not.toBeInTheDocument()

    await typeAmount(user, dialog, "5775")
    expect(confirm).toBeEnabled()
    await user.click(confirm)

    expect((await screen.findAllByText(/COR000009/)).length).toBeGreaterThan(0)
    await vi.waitFor(() => expect(paymentRequest).toBeDefined())
    expect(JSON.parse(paymentRequest?.body as string)).toEqual({
      promissory_note_on_file: false,
      amount: 5775,
    })
    expect(
      screen.getByRole("button", { name: "Print / download" }),
    ).toBeInTheDocument()
  })

  it("leaves the ticket to the payment confirmation instead of completing it a second time", async () => {
    const user = userEvent.setup()
    const ticketPatches: string[] = []
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/queue-tickets/") && init?.method === "PATCH") {
        ticketPatches.push(url(input))
      }
      return mockRoutes()(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    await continueAsRegularPayee(user)
    await typeAmount(user, screen.getByRole("alertdialog"), "5775")
    await user.click(
      within(screen.getByRole("alertdialog")).getByRole("button", {
        name: "Confirm payment",
      }),
    )

    await screen.findByRole("button", { name: "Payment processed" })
    // The server marks the ticket served when it confirms the payment; a second PATCH would only
    // be refused ("currently 'served'") and leave the Cashier's queue stale.
    expect(ticketPatches).toEqual([])
  })

  it("disables payment confirmation for the enrollment after it succeeds", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    await continueAsRegularPayee(user)
    await typeAmount(user, screen.getByRole("alertdialog"), "5775")
    await user.click(
      within(screen.getByRole("alertdialog")).getByRole("button", {
        name: "Confirm payment",
      }),
    )

    expect(
      await screen.findByRole("button", { name: "Payment processed" }),
    ).toBeDisabled()
  })

  it("finds an eligible student but blocks serving them while another ticket is active", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    const input = await screen.findByLabelText("Find student number")
    await user.type(input, "2026-0002")
    await user.click(screen.getByRole("button", { name: "Find student" }))

    expect(await screen.findByText("Juan Dela Cruz")).toBeInTheDocument()
    expect(
      screen.getByText("Skip the current ticket before serving this student."),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Serve selected student" }),
    ).not.toBeInTheDocument()
  })

  it("offers Confirm payment, Announce ticket and Skip on Now Serving, and no advance-payment actions", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    expect(await screen.findByRole("button", { name: "Confirm payment" })).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Skip" })).toBeInTheDocument()
    // Advance payment has its own page; the Announce ticket button must stay.
    expect(screen.queryByRole("button", { name: /Record balance/i })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /advance payment/i })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Adjust fees" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Complete" })).not.toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: /Announce ticket/i }))
    expect(playQueueAlert).toHaveBeenCalledWith("Q001")
  })

  it("sends the announcement to the server too, so the student's own device rings", async () => {
    const user = userEvent.setup()
    let announceBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/queue-tickets/") && init?.method === "PATCH") {
        announceBody = JSON.parse(init.body as string)
        return Promise.resolve(new Response(JSON.stringify({ data: servingTicket })))
      }
      return mockRoutes()(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    await user.click(await screen.findByRole("button", { name: /Announce ticket/i }))

    // The Cashier still hears it locally at once...
    expect(playQueueAlert).toHaveBeenCalledWith("Q001")
    // ...and the student's polled queue view is told, via the announce counter.
    await vi.waitFor(() => expect(announceBody).toEqual({ action: "announce" }))
  })

  it("requeues the currently serving ticket to the back of the waiting line", async () => {
    const user = userEvent.setup()
    let skipped = false
    const requeuedTicket = {
      ...servingTicket,
      status: "waiting",
      status_label: "Waiting",
      requeued_at: "2026-07-30T01:00:00Z",
    }
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/queue-tickets/1") && init?.method === "PATCH") {
        skipped = true
        return Promise.resolve(
          new Response(JSON.stringify({ data: requeuedTicket })),
        )
      }
      if (target.includes("/queue-tickets"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: skipped
                ? [waitingTicket, requeuedTicket]
                : [servingTicket, waitingTicket],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return mockRoutes()(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    await screen.findByText("Q001")
    await user.click(screen.getByRole("button", { name: "Skip" }))

    const table = await screen.findByRole("table", { name: "Waiting" })
    const rows = await within(table).findAllByRole("row")
    // header row, then Q002 (never requeued) ahead of Q001 (just
    // requeued) -- proving the skipped ticket lands at the back, not
    // simply back in the list in its old (lower-id) position.
    expect(within(rows[1]).getByText("Q002")).toBeInTheDocument()
    expect(within(rows[2]).getByText("Q001")).toBeInTheDocument()
  })

  it("sorts a never-requeued ticket before a requeued one on an exact effective-order tie", async () => {
    // Both tickets share the same effective order instant -- the requeued
    // ticket's requeued_at equals the never-requeued ticket's created_at --
    // and the requeued ticket deliberately has the LOWER id, to prove the
    // requeued_at-IS-NOT-NULL regime split (not `id`) breaks the tie, same
    // as QueueTicket::position()/ListQueueTickets server-side.
    const requeuedWaitingTicket = {
      ...servingTicket,
      id: 1,
      ticket_number: "Q001",
      status: "waiting",
      status_label: "Waiting",
      created_at: "2026-07-30T00:00:00Z",
      requeued_at: "2026-07-30T02:00:00Z",
    }
    const neverRequeuedWaitingTicket = {
      ...servingTicket,
      id: 5,
      ticket_number: "Q005",
      status: "waiting",
      status_label: "Waiting",
      created_at: "2026-07-30T02:00:00Z",
      requeued_at: null,
    }
    fetchMock.mockImplementation(
      mockRoutes({
        tickets: [requeuedWaitingTicket, neverRequeuedWaitingTicket],
      }),
    )
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    const table = await screen.findByRole("table", { name: "Waiting" })
    const rows = await within(table).findAllByRole("row")
    // header row, then Q005 (never requeued, higher id) ahead of Q001
    // (requeued, lower id) -- proving the regime split, not `id`, breaks
    // the exact effective-order tie.
    expect(within(rows[1]).getByText("Q005")).toBeInTheDocument()
    expect(within(rows[2]).getByText("Q001")).toBeInTheDocument()
  })

  it("marks a waiting ticket priority", async () => {
    const user = userEvent.setup()
    let priorityRequest: RequestInit | undefined
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/queue-tickets/2") && init?.method === "PATCH") {
        priorityRequest = init
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...waitingTicket, priority: "priority" },
            }),
          ),
        )
      }
      return mockRoutes()(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    const waitingTable = await screen.findByRole("table", { name: "Waiting" })
    await user.click(
      within(waitingTable).getByRole("button", { name: "Mark priority" }),
    )

    await vi.waitFor(() => expect(priorityRequest).toBeDefined())
    expect(JSON.parse(priorityRequest?.body as string)).toEqual({
      action: "mark_priority",
    })
  })

  it("renders each waiting ticket as a phone-sized action card", async () => {
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    const card = await screen.findByRole("article", {
      name: "Waiting ticket Q002",
    })
    expect(within(card).getByText("2026-0002")).toBeInTheDocument()
    expect(within(card).getByText("Amount due")).toBeInTheDocument()
    expect(within(card).getByText("—")).toBeInTheDocument()
    expect(
      within(card).getByRole("button", { name: "Mark priority" }),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation(mockRoutes())
    const { container } = renderWithSession(<AccountingPaymentWorkspace />, {
      session: accountingSession,
    })

    await screen.findByText("Q001")
    expect(await axe(container)).toHaveNoViolations()
  })

  it("asks whether the student is a regular payee or a scholar before showing the payment method", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await user.click(await screen.findByRole("button", { name: "Confirm payment" }))

    const dialog = await screen.findByRole("dialog", { name: "Payment classification" })
    expect(within(dialog).getByRole("radio", { name: /Regular payee/ })).toBeChecked()
    expect(within(dialog).getByRole("radio", { name: /Scholar/ })).not.toBeChecked()
    // The payment modal only comes after this choice.
    expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument()
  })

  it("takes a regular payee to the payment modal, which states and enforces the ₱1,000 minimum", async () => {
    const user = userEvent.setup()
    const calls: string[] = []
    fetchMock.mockImplementation(mockScholarshipRoutes(calls))
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await continueAsRegularPayee(user)
    const dialog = await screen.findByRole("alertdialog")
    const amount = within(dialog).getByLabelText("Amount")
    const confirm = within(dialog).getByRole("button", { name: "Confirm payment" })

    expect(within(dialog).getByText(/at least ₱1,000\.00/)).toBeInTheDocument()
    // It opens at 0.00 with Confirm disabled, and the untouched 0.00 is not shown as an error.
    expect(amount).toHaveValue("0.00")
    expect(confirm).toBeDisabled()
    expect(within(dialog).queryByRole("alert")).not.toBeInTheDocument()
    await user.clear(amount)
    await user.type(amount, "500")
    expect(within(dialog).getByRole("alert")).toHaveTextContent(/must be at least ₱1,000\.00/)
    expect(confirm).toBeDisabled()

    await user.clear(amount)
    await user.type(amount, "1000")
    // ₱1,000 is below the assessed total, so it is a partial payment. This student has a previous
    // bill (₱3,500.00), so it needs a promissory note on file (stakeholder Doc 14, S24; owner 2026-10-13).
    expect(within(dialog).getByRole("alert")).toHaveTextContent(/promissory note on file is required/)
    expect(confirm).toBeDisabled()
    await user.click(within(dialog).getByLabelText("Promissory note on file"))
    expect(within(dialog).queryByRole("alert")).not.toBeInTheDocument()
    expect(confirm).toBeEnabled()
    // A payee with no scholarship needs no change on the server.
    expect(calls).toEqual([])
  })

  it("needs no promissory note for a downpayment when the student has no previous bill", async () => {
    const user = userEvent.setup()
    let paymentBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/enrollments/9/payment") && init?.method === "POST") {
        paymentBody = JSON.parse(init.body as string)
      }
      return mockRoutes({ account: studentAccountNoPriorBill })(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await screen.findByText("Q001")
    await continueAsRegularPayee(user)
    const dialog = await screen.findByRole("alertdialog")
    const confirm = within(dialog).getByRole("button", { name: "Confirm payment" })
    // Nothing is owed from before, so the screen never asks for a note (owner, 2026-10-13).
    await vi.waitFor(() => expect(within(dialog).getByText(/Total Account Outstanding/)).toBeInTheDocument())

    await typeAmount(user, dialog, "1000")

    expect(within(dialog).queryByLabelText("Promissory note on file")).not.toBeInTheDocument()
    expect(within(dialog).queryByRole("alert")).not.toBeInTheDocument()
    expect(within(dialog).getByText(/No promissory note is needed because there is no previous bill/)).toBeInTheDocument()
    expect(confirm).toBeEnabled()
    await user.click(confirm)

    expect((await screen.findAllByText(/COR000009/)).length).toBeGreaterThan(0)
    expect(paymentBody).toEqual({ amount: 1000, promissory_note_on_file: false })
  })

  it("offers the promissory note when the server finds a previous bill the screen did not know about", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      if (url(input).endsWith("/enrollments/9/payment") && init?.method === "POST") {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "Validation failed.",
                errors: { promissory_note_on_file: ["A promissory note on file is required for partial payments."] },
                request_id: "r1",
              },
            }),
            { status: 422 },
          ),
        )
      }
      return mockRoutes({ account: studentAccountNoPriorBill })(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await screen.findByText("Q001")
    await continueAsRegularPayee(user)
    const dialog = await screen.findByRole("alertdialog")
    await typeAmount(user, dialog, "1000")
    expect(within(dialog).queryByLabelText("Promissory note on file")).not.toBeInTheDocument()
    await user.click(within(dialog).getByRole("button", { name: "Confirm payment" }))

    // The checkbox appears so the Cashier can tick it and send again.
    expect(await within(dialog).findByLabelText("Promissory note on file")).toBeInTheDocument()
    expect(within(dialog).getByRole("button", { name: "Confirm payment" })).toBeDisabled()
  })

  it("lets a scholar pick a tier, deducts it automatically, and opens the payment modal on the net", async () => {
    const user = userEvent.setup()
    const calls: string[] = []
    fetchMock.mockImplementation(mockScholarshipRoutes(calls))
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await user.click(await screen.findByRole("button", { name: "Confirm payment" }))
    const classification = await screen.findByRole("dialog", { name: "Payment classification" })
    await user.click(within(classification).getByRole("radio", { name: /Scholar/ }))
    await user.click(within(classification).getByRole("button", { name: "Continue" }))

    const tiers = await screen.findByRole("dialog", { name: "Scholarship classification" })
    await user.click(within(tiers).getByRole("radio", { name: /40% Partial Scholarship/ }))
    // 40% of 5,775.00 is 2,310.00, leaving 3,465.00.
    expect(within(tiers).getByText("-₱2,310.00")).toBeInTheDocument()
    expect(within(tiers).getByText("₱3,465.00")).toBeInTheDocument()
    await user.click(within(tiers).getByRole("button", { name: "Apply scholarship" }))

    const payment = await screen.findByRole("alertdialog")
    expect(calls).toEqual(["PUT 40"])
    // The amount starts at 0.00 even with a scholarship; the net it is paid against is on the summary.
    expect(within(payment).getByLabelText("Amount")).toHaveValue("0.00")
    expect(within(payment).getByText("Current Term Assessment:").nextSibling).toHaveTextContent("₱3,465.00")
    expect(within(payment).getByText("Scholarship discount (40%):")).toBeInTheDocument()
    expect(within(payment).getByText("-₱2,310.00")).toBeInTheDocument()
  })

  it("needs no payment amount for a 100% scholarship and still confirms", async () => {
    const user = userEvent.setup()
    const calls: string[] = []
    let paymentBody: unknown = null
    const routes = mockScholarshipRoutes(calls)
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/enrollments/9/payment") && init?.method === "POST") {
        paymentBody = JSON.parse(init.body as string)
      }
      return routes(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await user.click(await screen.findByRole("button", { name: "Confirm payment" }))
    let dialog = await screen.findByRole("dialog", { name: "Payment classification" })
    await user.click(within(dialog).getByRole("radio", { name: /Scholar/ }))
    await user.click(within(dialog).getByRole("button", { name: "Continue" }))
    dialog = await screen.findByRole("dialog", { name: "Scholarship classification" })
    // 100% is the default tier.
    expect(within(dialog).getByRole("radio", { name: /100% Full Academic/ })).toBeChecked()
    await user.click(within(dialog).getByRole("button", { name: "Apply scholarship" }))

    const payment = await screen.findByRole("alertdialog")
    expect(calls).toEqual(["PUT 100"])
    expect(within(payment).getByText(/No payment is due/)).toBeInTheDocument()
    await user.click(within(payment).getByRole("button", { name: "Confirm payment" }))

    await vi.waitFor(() => expect(paymentBody).not.toBeNull())
    expect(paymentBody).toEqual({ promissory_note_on_file: false })
  })

  it("collects a net below ₱1,000 in full without asking for an amount", async () => {
    const user = userEvent.setup()
    const calls: string[] = []
    let paymentBody: unknown = null
    // 20% off a 750.00 assessment leaves 600.00, which is under the minimum.
    const routes = mockScholarshipRoutes(calls, null, 750)
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/enrollments/9/payment") && init?.method === "POST") {
        paymentBody = JSON.parse(init.body as string)
      }
      return routes(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await user.click(await screen.findByRole("button", { name: "Confirm payment" }))
    let dialog = await screen.findByRole("dialog", { name: "Payment classification" })
    await user.click(within(dialog).getByRole("radio", { name: /Scholar/ }))
    await user.click(within(dialog).getByRole("button", { name: "Continue" }))
    dialog = await screen.findByRole("dialog", { name: "Scholarship classification" })
    await user.click(within(dialog).getByRole("radio", { name: /20% Partial Scholarship/ }))
    await user.click(within(dialog).getByRole("button", { name: "Apply scholarship" }))

    const payment = await screen.findByRole("alertdialog")
    const amount = within(payment).getByLabelText("Amount")
    expect(amount).toHaveValue("600.00")
    expect(amount).toHaveAttribute("readonly")
    expect(within(payment).queryByRole("alert")).not.toBeInTheDocument()
    await user.click(within(payment).getByRole("button", { name: "Confirm payment" }))

    await vi.waitFor(() => expect(paymentBody).not.toBeNull())
    expect(paymentBody).toEqual({ promissory_note_on_file: false })
  })

  it("removes an existing scholarship when the cashier chooses Regular payee", async () => {
    const user = userEvent.setup()
    const calls: string[] = []
    fetchMock.mockImplementation(mockScholarshipRoutes(calls, 40))
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await user.click(await screen.findByRole("button", { name: "Confirm payment" }))
    const dialog = await screen.findByRole("dialog", { name: "Payment classification" })
    // The student is already a scholar, so that is what is offered first.
    expect(within(dialog).getByRole("radio", { name: /Scholar/ })).toBeChecked()
    await user.click(within(dialog).getByRole("radio", { name: /Regular payee/ }))
    await user.click(within(dialog).getByRole("button", { name: "Continue" }))

    const payment = await screen.findByRole("alertdialog")
    expect(calls).toEqual(["DELETE"])
    expect(within(payment).getByLabelText("Amount")).toHaveValue("0.00")
    expect(within(payment).queryByText(/Scholarship discount/)).not.toBeInTheDocument()
  })

  it("shows why a scholarship could not be applied and stays on that step", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/scholarship-discount") && init?.method === "PUT") {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_ERROR",
                message: "The given data was invalid.",
                errors: { enrollment: ["A scholarship cannot be changed after payment confirmation."] },
                request_id: "req-1",
              },
            }),
            { status: 422 },
          ),
        )
      }
      return mockRoutes()(input, init)
    })
    renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

    await user.click(await screen.findByRole("button", { name: "Confirm payment" }))
    let dialog = await screen.findByRole("dialog", { name: "Payment classification" })
    await user.click(within(dialog).getByRole("radio", { name: /Scholar/ }))
    await user.click(within(dialog).getByRole("button", { name: "Continue" }))
    dialog = await screen.findByRole("dialog", { name: "Scholarship classification" })
    await user.click(within(dialog).getByRole("button", { name: "Apply scholarship" }))

    expect(await within(dialog).findByText(/cannot be changed after payment confirmation/)).toBeInTheDocument()
    expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument()
  })

  describe("full-payment discount (ADR 0046)", () => {
    let paymentBody: unknown

    function routesWith(quote: unknown) {
      return (input: RequestInfo | URL, init?: RequestInit) => {
        if (url(input).includes("/enrollments/9/payment") && !url(input).includes("quote") && init?.method === "POST") {
          paymentBody = JSON.parse(init.body as string)
        }
        return mockRoutes({ quote })(input, init)
      }
    }

    beforeEach(() => {
      paymentBody = undefined
    })

    it("offers Pay in full and Installment when a discount applies, and pays the quoted net", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(routesWith(discountQuote))
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      await screen.findByText("Q001")
      await continueAsRegularPayee(user)
      const dialog = await screen.findByRole("alertdialog")

      const choice = await within(dialog).findByRole("group", { name: "Payment type" })
      // Installment is the default (owner, 2026-10-13), with the amount at 0.00 and no discount shown.
      expect(within(choice).getByRole("button", { name: /Installment/ })).toHaveAttribute("aria-pressed", "true")
      expect(within(choice).getByRole("button", { name: /Pay in full/ })).toHaveAttribute("aria-pressed", "false")
      expect(within(dialog).getByLabelText("Amount")).toHaveValue("0.00")
      expect(within(dialog).queryByText(/Full-payment discount \(10%\)/)).not.toBeInTheDocument()

      await user.click(within(choice).getByRole("button", { name: /Pay in full/ }))

      expect(within(choice).getByRole("button", { name: /Pay in full/ })).toHaveAttribute("aria-pressed", "true")
      expect(within(dialog).getByLabelText("Amount")).toHaveValue("5197.50")
      expect(within(dialog).getByLabelText("Amount")).toHaveAttribute("readonly")
      expect(within(dialog).getByText(/Full-payment discount \(10%\)/)).toBeInTheDocument()
      expect(within(dialog).getByText("-₱577.50")).toBeInTheDocument()
      // Paying in full is never a partial payment: no promissory note, no partial-payment warning.
      expect(within(dialog).queryByLabelText("Promissory note on file")).not.toBeInTheDocument()
      expect(within(dialog).queryByText(/Partial payment detected/)).not.toBeInTheDocument()

      await user.click(within(dialog).getByRole("button", { name: "Confirm payment" }))

      expect((await screen.findAllByText(/COR000009/)).length).toBeGreaterThan(0)
      expect(paymentBody).toEqual({ payment_type: "full", quoted_amount: "5197.50" })
    })

    it("keeps today's amount, minimum and promissory note for an installment and sends no discount", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(routesWith(discountQuote))
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      await screen.findByText("Q001")
      await continueAsRegularPayee(user)
      const dialog = await screen.findByRole("alertdialog")
      expect(await within(dialog).findByRole("button", { name: /Installment/ })).toHaveAttribute("aria-pressed", "true")

      const amount = within(dialog).getByLabelText("Amount")
      expect(amount).not.toHaveAttribute("readonly")
      expect(amount).toHaveValue("0.00")
      await user.clear(amount)
      await user.type(amount, "1000")
      await user.click(within(dialog).getByLabelText("Promissory note on file"))
      await user.click(within(dialog).getByRole("button", { name: "Confirm payment" }))

      expect((await screen.findAllByText(/COR000009/)).length).toBeGreaterThan(0)
      expect(paymentBody).toEqual({ amount: 1000, promissory_note_on_file: true })
    })

    it("names the discounted assessment in the prior-balance note while paying in full", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(routesWith(discountQuote))
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      await screen.findByText("Q001")
      await continueAsRegularPayee(user)
      const dialog = await screen.findByRole("alertdialog")
      const choice = await within(dialog).findByRole("group", { name: "Payment type" })

      // Installment is the default: the note names the full assessment.
      expect(within(dialog).getByText(/This payment clears the current term assessment/)).toHaveTextContent(
        "current term assessment (₱5,775.00)",
      )

      await user.click(within(choice).getByRole("button", { name: /Pay in full/ }))

      // The student owes a prior-term balance, so the note is shown; paying in full clears the NET.
      const note = within(dialog).getByText(/This payment clears the current term assessment/)
      expect(note).toHaveTextContent("current term assessment (₱5,197.50)")
      expect(note).not.toHaveTextContent("₱5,775.00")
    })

    it("leaves the modal as it is and says so when no discount is active", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(
        routesWith({
          ...noDiscountQuote,
          full_payment: { ...noDiscountQuote.full_payment, reason: "expired" },
        }),
      )
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      await screen.findByText("Q001")
      await continueAsRegularPayee(user)
      const dialog = await screen.findByRole("alertdialog")

      expect(await within(dialog).findByText("No full-payment discount is active.")).toBeInTheDocument()
      expect(within(dialog).queryByRole("group", { name: "Payment type" })).not.toBeInTheDocument()
      expect(within(dialog).getByLabelText("Amount")).toHaveValue("0.00")
    })

    it("says nothing about a discount for a scholar", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(
        routesWith({
          ...noDiscountQuote,
          full_payment: { ...noDiscountQuote.full_payment, reason: "scholar" },
        }),
      )
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      await screen.findByText("Q001")
      await continueAsRegularPayee(user)
      const dialog = await screen.findByRole("alertdialog")

      await within(dialog).findByLabelText("Amount")
      expect(within(dialog).queryByText("No full-payment discount is active.")).not.toBeInTheDocument()
      expect(within(dialog).queryByRole("group", { name: "Payment type" })).not.toBeInTheDocument()
    })

    it("still lets the Cashier take the payment when the quote cannot be read", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation(routesWith("fail"))
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      await screen.findByText("Q001")
      await continueAsRegularPayee(user)
      const dialog = await screen.findByRole("alertdialog")

      expect(await within(dialog).findByText(/could not be checked/)).toBeInTheDocument()
      await typeAmount(user, dialog, "1000")
      await user.click(within(dialog).getByLabelText("Promissory note on file"))
      await user.click(within(dialog).getByRole("button", { name: "Confirm payment" }))

      expect((await screen.findAllByText(/COR000009/)).length).toBeGreaterThan(0)
      expect(paymentBody).toEqual({ amount: 1000, promissory_note_on_file: true })
    })

    it("shows the new amount when the discount changed under the Cashier", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation((input, init) => {
        if (url(input).endsWith("/enrollments/9/payment") && init?.method === "POST") {
          return Promise.resolve(
            new Response(
              JSON.stringify({
                error: {
                  code: "VALIDATION_FAILED",
                  message: "Validation failed.",
                  errors: { quoted_amount: ["The amount due changed. Review the new amount and confirm again."] },
                  request_id: "r1",
                },
              }),
              { status: 422 },
            ),
          )
        }
        return mockRoutes({ quote: discountQuote })(input, init)
      })
      renderWithSession(<AccountingPaymentWorkspace />, { session: accountingSession })

      await screen.findByText("Q001")
      await continueAsRegularPayee(user)
      const dialog = await screen.findByRole("alertdialog")
      const choice = await within(dialog).findByRole("group", { name: "Payment type" })
      await user.click(within(choice).getByRole("button", { name: /Pay in full/ }))
      await user.click(within(dialog).getByRole("button", { name: "Confirm payment" }))

      expect(await within(dialog).findByText(/The amount due changed/)).toBeInTheDocument()
      // The dialog stays open so the Cashier can review the refreshed quote.
      expect(screen.getByRole("alertdialog")).toBeInTheDocument()
    })
  })
})
