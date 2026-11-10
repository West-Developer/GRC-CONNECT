import { describe, expect, it } from "vitest"

import { enrollmentSchema } from "@/features/schemas/enrollment-schema"
import { freshmanPaymentPreviewSchema } from "@/features/schemas/freshman-schema"
import { queueTicketResourceSchema } from "@/features/schemas/queue-ticket-schema"
import { studentQueueViewSchema } from "@/features/schemas/student-queue-schema"

// What the API now sends for a student who pays first (ADR 0043, amended 2026-10-05):
// a transferee's payment hold, the queue stages that go with it, and the Cashier's flag.

const enrollment = {
  type: "enrollment",
  id: 31,
  student_id: 4,
  student_number: "2026-0900",
  student_name: null,
  student_year_level: null,
  student_financial_status: null,
  student_financial_status_label: null,
  academic_term_id: 2,
  status: "awaiting_first_payment",
  status_label: "Awaiting First Payment",
  total_units: 3,
  requires_overload_approval: false,
  program_head_comment: null,
  submitted_at: "2026-10-05T00:00:00Z",
  program_head_decided_at: null,
  registrar_decided_at: null,
  payment_confirmed_at: null,
  enrolled_at: null,
  subjects: [],
  revisions: [],
  queue_ticket: null,
  assessment: null,
}

const ticket = {
  type: "queue_ticket",
  id: 7,
  enrollment_id: null,
  student_id: 4,
  student_number: "2026-0900",
  is_freshman: false,
  ticket_number: "Q007",
  queue_date: "2026-10-05",
  status: "waiting",
  status_label: "Waiting",
  priority: "regular",
  priority_label: "Regular",
  created_at: "2026-10-05T00:00:00Z",
  served_at: null,
  requeued_at: null,
}

const queueView = {
  type: "student_queue_view",
  stage: "awaiting_first_payment",
  can_claim: false,
  ticket: null,
  now_serving_ticket_number: null,
  upcoming_ticket_numbers: [],
  cut_off_today: false,
}

const freshmanPreview = {
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
}

describe("the pay-first contract", () => {
  it("accepts the payment preview of a transferee, with the subjects they chose", () => {
    const parsed = freshmanPaymentPreviewSchema.parse({
      ...freshmanPreview,
      student_type: "transferee",
      can_enroll: true,
      block: null,
      enrollment: {
        id: 31,
        status: "awaiting_first_payment",
        status_label: "Awaiting First Payment",
        total_units: 21,
        subjects: [{ code: "ITP1", title: "Programming 1", units: 3 }],
      },
    })

    expect(parsed.student_type).toBe("transferee")
    expect(parsed.enrollment?.subjects[0].code).toBe("ITP1")
  })

  it("still accepts a freshman's preview from a server that predates the transferee keys", () => {
    const parsed = freshmanPaymentPreviewSchema.parse(freshmanPreview)

    expect(parsed.student_type).toBeNull()
    expect(parsed.enrollment).toBeNull()
  })

  it("accepts an enrollment at the payment hold", () => {
    expect(enrollmentSchema.parse(enrollment).status).toBe(
      "awaiting_first_payment",
    )
  })

  it.each([
    "awaiting_first_payment",
    "transferee_awaiting_payment",
    "transferee_paid",
    "transferee_enrolled",
  ])("accepts the queue stage %s", (stage) => {
    expect(studentQueueViewSchema.parse({ ...queueView, stage }).stage).toBe(
      stage,
    )
  })

  it("tells a transferee's ticket from a freshman's, and defaults the flag for a server that predates it", () => {
    expect(
      queueTicketResourceSchema.parse({ ...ticket, is_transferee: true })
        .is_transferee,
    ).toBe(true)
    expect(queueTicketResourceSchema.parse(ticket).is_transferee).toBe(false)
  })
})
