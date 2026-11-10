import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  confirmPayment,
  createEnrollment,
  getEligibleSubjects,
  getEnrollments,
  getPaymentQuote,
  listEnrollments,
  updateEnrollment,
} from "@/features/services/enrollment-service"

const paginationLinks = {
  first: "https://api.test/enrollments?page=1",
  last: "https://api.test/enrollments?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const eligibleSubject = {
  type: "eligible_subject",
  subject_id: 7,
  code: "CS101",
  title: "Programming 1",
  units: 3,
  paired_subject_id: null,
  year_level: 1,
  semester: "1st",
  is_required: true,
  is_eligible: true,
  reasons: [{ code: "eligible", message: "All requirements are met." }],
  preference_score: null,
  preference_reasons: [],
  available_sections: [
    {
      type: "section",
      id: 5,
      academic_term_id: 2,
      subject_id: 7,
      section_code: "A",
      professor_id: null,
      schedule_days: "MWF",
      starts_at_time: "08:00:00",
      ends_at_time: "09:00:00",
      room: "R101",
      capacity: 30,
      capacity_source: "plan",
      viability_threshold: null,
      enrolled_count: 0,
      remaining_seats: 30,
      is_block_exclusive: null,
      status: "published",
      status_label: "Published",
      college: "ccs",
      is_own_department: true,
      subject_code: "CS101",
      subject_title: "Programming 1",
    },
  ],
} as const

const enrollment = {
  type: "enrollment",
  id: 9,
  student_id: 3,
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
  subjects: [
    {
      section_id: 5,
      subject_id: 50,
      subject_code: "CS101",
      paired_subject_id: null,
      subject_title: "Programming 1",
      status: "selected",
      status_label: "Selected",
    },
  ],
  revisions: [],
  queue_ticket: {
    ticket_number: "Q000009",
    queue_date: "2026-07-30",
    status: "waiting",
    status_label: "Waiting",
    priority: "regular",
    priority_label: "Regular",
    position: 0,
  },
  assessment: null,
} as const

describe("enrollment-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("fetches the eligible-subject pool for the given term", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: [eligibleSubject] })),
    )

    const result = await getEligibleSubjects(2)

    expect(result).toEqual([eligibleSubject])
    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "/eligible-subjects?academic_term_id=2",
    )
  })

  it("requests eligibility for a particular enrollment revision", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: [eligibleSubject] })),
    )
    await getEligibleSubjects(2, undefined, 21)
    expect(fetchMock.mock.calls.at(-1)?.[0]).toContain(
      "academic_term_id=2&enrollment_id=21",
    )
  })

  it("still parses an enrollment from a backend that predates the Program Chair's change history", async () => {
    const withoutRevisions: Record<string, unknown> = { ...enrollment }
    delete withoutRevisions.revisions
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: [withoutRevisions],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )

    const result = await getEnrollments()

    expect(result[0]?.revisions).toEqual([])
  })

  it("fetches the authenticated student's enrollments", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: [enrollment],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )

    const result = await getEnrollments()

    expect(result).toEqual([enrollment])
    expect(fetchMock.mock.calls[0]?.[0]).toContain("/enrollments")
  })

  it("lists role-scoped enrollments with filters and pagination", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: [enrollment],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )

    const result = await listEnrollments({
      status: "pending_registrar_approval",
      page: 1,
      per_page: 20,
    })

    expect(result.data).toEqual([enrollment])
    expect(result.meta).toEqual(paginationMeta)
    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "status=pending_registrar_approval&page=1&per_page=20",
    )
  })

  it("serializes bounded cashier enrollment ids and boolean queue filters", async () => {
    fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ data: [], links: paginationLinks, meta: paginationMeta })))
    await listEnrollments({ status: "pending_payment", enrollment_ids: [9, 120], without_queue_ticket: true, current_term: true })
    const query = new URL(fetchMock.mock.calls[0]?.[0] as string).searchParams
    expect(query.getAll("enrollment_ids[]")).toEqual(["9", "120"])
    expect(query.get("without_queue_ticket")).toBe("1")
    expect(query.get("current_term")).toBe("1")
  })

  it("applies a registrar decision", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({ data: { ...enrollment, status: "rejected" } }),
      ),
    )

    const result = await updateEnrollment(9, {
      action: "registrar_reject",
      reason: "Missing prerequisite.",
    })

    expect(result.status).toBe("rejected")
    expect(fetchMock.mock.calls[0]?.[0]).toContain("/enrollments/9")
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("PATCH")
    expect(JSON.parse(fetchMock.mock.calls[0]?.[1]?.body as string)).toEqual({
      action: "registrar_reject",
      reason: "Missing prerequisite.",
    })
  })

  it("confirms payment and parses the enrollment/payment/document bundle", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: {
            enrollment: { ...enrollment, status: "enrolled" },
            payment: {
              external_reference: "OR-000123",
              amount: "1500.00",
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

    const result = await confirmPayment(9, { external_reference: "OR-000123" })

    expect(result.enrollment.status).toBe("enrolled")
    expect(result.document.document_number).toBe("COR000009")
    expect(fetchMock.mock.calls[0]?.[0]).toContain("/enrollments/9/payment")
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("POST")
  })

  it("submits an enrollment and parses the created resource", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: enrollment }), { status: 201 }),
    )

    const result = await createEnrollment({
      academic_term_id: 2,
      sections: [{ section_id: 5 }],
    })

    expect(result).toEqual(enrollment)
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("POST")
    expect(JSON.parse(fetchMock.mock.calls[0]?.[1]?.body as string)).toEqual({
      academic_term_id: 2,
      sections: [{ section_id: 5 }],
    })
  })

  it("reads the payment quote for an enrollment", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            type: "payment_quote",
            total_amount: "1050.00",
            full_payment: {
              applies: true,
              percentage: "10.0",
              discount_amount: "105.00",
              net_total: "945.00",
              reason: null,
            },
          },
        }),
      ),
    )

    const quote = await getPaymentQuote(9)

    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "/api/v1/enrollments/9/payment-quote",
    )
    expect(quote.full_payment.net_total).toBe("945.00")
  })

  it("sends a full payment with its quoted amount and no amount", async () => {
    fetchMock.mockResolvedValue(new Response("{}", { status: 500 }))

    await confirmPayment(9, {
      payment_type: "full",
      quoted_amount: "945.00",
    }).catch(() => undefined)

    const [, init] = fetchMock.mock.calls[0]
    expect(JSON.parse(init?.body as string)).toEqual({
      payment_type: "full",
      quoted_amount: "945.00",
    })
  })
})
