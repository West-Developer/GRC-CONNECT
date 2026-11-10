import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { EnrollmentQueuePaymentPanel } from "@/features/components/portal/enrollment-queue-payment-panel"
import type { Enrollment } from "@/features/schemas/enrollment-schema"
import { renderWithSession } from "@/tests/render-app"

const baseEnrollment: Enrollment = {
  type: "enrollment",
  id: 9,
  student_id: 4,
  student_number: "2026-0001",
  student_name: null,
  student_year_level: null,
  student_financial_status: null,
  student_financial_status_label: null,
  academic_term_id: 2,
  status: "pending_registrar_approval",
  status_label: "Pending Registrar Approval",
  total_units: 3,
  requires_overload_approval: false,
  program_head_comment: null,
  submitted_at: "2026-07-30T00:00:00Z",
  program_head_decided_at: null,
  registrar_decided_at: null,
  payment_confirmed_at: null,
  enrolled_at: null,
  subjects: [],
  revisions: [],
  queue_ticket: null,
  assessment: null,
}

const liveQueueView = {
  type: "student_queue_view",
  stage: "pending_payment",
  can_claim: false,
  ticket: {
    ticket_number: "Q-LIVE-007",
    status: "waiting",
    status_label: "Waiting",
    priority: "regular",
    priority_label: "Regular",
    position: 2,
  },
  now_serving_ticket_number: "Q-LIVE-005",
  upcoming_ticket_numbers: ["Q-LIVE-006", "Q-LIVE-007"],
  cut_off_today: false,
} as const

describe("EnrollmentQueuePaymentPanel", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    fetchMock.mockReset()
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify({ data: liveQueueView }))),
    )
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it("shows the enrollment id and status", () => {
    renderWithSession(
      <EnrollmentQueuePaymentPanel enrollment={baseEnrollment} />,
    )

    expect(screen.getByText("Enrollment #9")).toBeInTheDocument()
    expect(screen.getByText("Pending Registrar Approval")).toBeInTheDocument()
  })

  it("shows the assessed amount due and its breakdown once assessed", () => {
    renderWithSession(
      <EnrollmentQueuePaymentPanel
        enrollment={{
          ...baseEnrollment,
          status: "pending_payment",
          program_head_decided_at: null,
          registrar_decided_at: "2026-07-31T00:00:00Z",
          assessment: {
            total_amount: "5775.00",
            currency: "PHP",
            assessed_at: "2026-07-31T00:00:00Z",
            items: [
              {
                category: "tuition",
                category_label: "Tuition",
                label: "Tuition",
                quantity: "10.5",
                unit_amount: "450.00",
                amount: "4725.00",
              },
              {
                category: "miscellaneous",
                category_label: "Miscellaneous",
                label: "Registration",
                quantity: null,
                unit_amount: null,
                amount: "350.00",
              },
            ],
          },
        }}
      />,
    )

    expect(screen.getByText("₱5775.00")).toBeInTheDocument()
    expect(screen.getByText("₱4725.00")).toBeInTheDocument()
    expect(screen.getByText("₱350.00")).toBeInTheDocument()
    expect(screen.getByText("Registration")).toBeInTheDocument()
  })

  it("lists a scholarship discount as a deduction and shows the net as the amount due", () => {
    renderWithSession(
      <EnrollmentQueuePaymentPanel
        enrollment={{
          ...baseEnrollment,
          status: "pending_payment",
          program_head_decided_at: null,
          registrar_decided_at: "2026-07-31T00:00:00Z",
          assessment: {
            total_amount: "3465.00",
            currency: "PHP",
            assessed_at: "2026-07-31T00:00:00Z",
            items: [
              {
                category: "tuition",
                category_label: "Tuition",
                label: "Tuition",
                quantity: "10.5",
                unit_amount: "450.00",
                amount: "4725.00",
              },
              {
                category: "miscellaneous",
                category_label: "Miscellaneous",
                label: "Registration",
                quantity: null,
                unit_amount: null,
                amount: "1050.00",
              },
              {
                category: "scholarship_discount",
                category_label: "Scholarship discount",
                label: "Scholarship discount (40%)",
                quantity: "40.0",
                unit_amount: null,
                amount: "-2310.00",
              },
            ],
          },
        }}
      />,
    )

    expect(screen.getByText("₱3465.00")).toBeInTheDocument()
    expect(screen.getByText("Scholarship discount (40%)")).toBeInTheDocument()
    expect(screen.getByText("−₱2310.00")).toBeInTheDocument()
  })

  it("does not show an amount-due section before assessment", () => {
    renderWithSession(
      <EnrollmentQueuePaymentPanel enrollment={baseEnrollment} />,
    )

    expect(screen.queryByText("Amount due")).not.toBeInTheDocument()
  })

  it("shows the payment confirmation date once paid", () => {
    renderWithSession(
      <EnrollmentQueuePaymentPanel
        enrollment={{
          ...baseEnrollment,
          status: "enrolled",
          program_head_decided_at: null,
          registrar_decided_at: "2026-07-31T00:00:00Z",
          payment_confirmed_at: "2026-08-01T00:00:00Z",
          enrolled_at: "2026-08-01T00:00:00Z",
        }}
      />,
    )

    expect(
      screen.getByText(new Date("2026-08-01T00:00:00Z").toLocaleString()),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations", async () => {
    const { container } = renderWithSession(
      <EnrollmentQueuePaymentPanel enrollment={baseEnrollment} />,
    )

    expect(await axe(container)).toHaveNoViolations()
  })

  it("replaces the enrollment snapshot ticket with the current live queue view", async () => {
    renderWithSession(
      <EnrollmentQueuePaymentPanel
        enrollment={{
          ...baseEnrollment,
          status: "pending_payment",
          program_head_decided_at: null,
          registrar_decided_at: "2026-07-31T00:00:00Z",
          queue_ticket: {
            ticket_number: "Q-STALE-001",
            queue_date: "2026-07-31",
            status: "waiting",
            status_label: "Waiting",
            priority: "regular",
            priority_label: "Regular",
            position: 0,
          },
        }}
      />,
    )

    const queueRegion = await screen.findByRole("region", {
      name: "Your Cashier queue",
    })
    expect(
      within(queueRegion).getAllByText("Q-LIVE-007").length,
    ).toBeGreaterThan(0)
    expect(screen.queryByText("Q-STALE-001")).not.toBeInTheDocument()
    expect(within(queueRegion).getByText("Q-LIVE-005")).toBeInTheDocument()
  })

  it("makes the live queue loading and retryable failure states explicit", async () => {
    const user = userEvent.setup()
    let resolveQueue: ((response: Response) => void) | undefined
    fetchMock.mockImplementationOnce(
      () =>
        new Promise<Response>((resolve) => {
          resolveQueue = resolve
        }),
    )

    renderWithSession(
      <EnrollmentQueuePaymentPanel enrollment={baseEnrollment} />,
    )

    expect(screen.getByText("Loading your Cashier queue…")).toBeInTheDocument()

    await waitFor(() => expect(resolveQueue).toBeTypeOf("function"))

    resolveQueue?.(
      new Response(JSON.stringify({ message: "Unavailable" }), {
        status: 400,
      }),
    )
    expect(
      await screen.findByRole("button", { name: "Retry queue status" }),
    ).toBeInTheDocument()

    fetchMock.mockImplementationOnce(() =>
      Promise.resolve(new Response(JSON.stringify({ data: liveQueueView }))),
    )
    await user.click(screen.getByRole("button", { name: "Retry queue status" }))
    expect(
      await screen.findByRole("region", { name: "Your Cashier queue" }),
    ).toBeInTheDocument()
  })

  it("guides claim-eligible students to the Cashier kiosk without a claim action", async () => {
    fetchMock.mockImplementationOnce(() =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            data: {
              ...liveQueueView,
              can_claim: true,
              ticket: null,
            },
          }),
        ),
      ),
    )

    renderWithSession(
      <EnrollmentQueuePaymentPanel enrollment={baseEnrollment} />,
    )

    expect(
      await screen.findByText(
        /Claim your queuing ticket in person at the school Cashier kiosk/i,
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: /claim/i }),
    ).not.toBeInTheDocument()
  })

  it("does not show queue ticket or cashier queue when student is officially enrolled", () => {
    renderWithSession(
      <EnrollmentQueuePaymentPanel
        enrollment={{
          ...baseEnrollment,
          status: "enrolled",
          status_label: "Enrolled",
          payment_confirmed_at: "2026-08-01T00:00:00Z",
          enrolled_at: "2026-08-01T00:00:00Z",
        }}
      />,
    )

    expect(
      screen.queryByText("Loading your Cashier queue…"),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("region", { name: "Your Cashier queue" }),
    ).not.toBeInTheDocument()
    expect(screen.getByText("Enrolled")).toBeInTheDocument()
  })

  describe("a freshman whose first payment is already held", () => {
    it("is told the payment is on file instead of being sent to a queue", () => {
      renderWithSession(
        <EnrollmentQueuePaymentPanel
          enrollment={baseEnrollment}
          advancePaymentHeld
        />,
      )

      expect(screen.getByText("Your payment is already on file")).toBeInTheDocument()
      expect(screen.getByText(/no queue number to claim/i)).toBeInTheDocument()
      expect(screen.getByText("On file — applied when approved")).toBeInTheDocument()
      expect(screen.queryByText("Not yet")).not.toBeInTheDocument()
      // The Cashier queue is not even read: there is no ticket for this enrollment.
      expect(fetchMock).not.toHaveBeenCalled()
      expect(
        screen.queryByRole("region", { name: "Your Cashier queue" }),
      ).not.toBeInTheDocument()
    })

    it("sees nothing special once the enrollment is done", () => {
      renderWithSession(
        <EnrollmentQueuePaymentPanel
          enrollment={{
            ...baseEnrollment,
            status: "enrolled",
            status_label: "Enrolled",
            payment_confirmed_at: "2026-08-01T00:00:00Z",
            enrolled_at: "2026-08-01T00:00:00Z",
          }}
          advancePaymentHeld
        />,
      )

      expect(
        screen.queryByText("Your payment is already on file"),
      ).not.toBeInTheDocument()
    })

    it("a student with nothing held still gets the usual queue guidance", async () => {
      renderWithSession(
        <EnrollmentQueuePaymentPanel enrollment={baseEnrollment} />,
      )

      expect(
        await screen.findByRole("region", { name: "Your Cashier queue" }),
      ).toBeInTheDocument()
      expect(
        screen.queryByText("Your payment is already on file"),
      ).not.toBeInTheDocument()
      expect(screen.getByText("Not yet")).toBeInTheDocument()
    })
  })
})
