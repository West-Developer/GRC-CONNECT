import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  decideProfileChangeRequest,
  getNextStudentNumber,
  listStudentProfiles,
  provisionStudent,
  requestStudentAccountSetupResend,
  resendQueueLink,
  setupStudentAccount,
  type ProvisionStudentInput,
} from "@/features/services/admission-service"

const NOTICE_VERSION = "2026-10-18"

const input: ProvisionStudentInput = {
  first_name: "Amina",
  middle_name: "Santos",
  last_name: "Santos",
  email: "amina.santos@grc.test",
  gender: "F",
  birth_date: "2006-05-04",
  citizenship: "Filipino",
  contact_number: "09171234567",
  street_barangay: "123 Mabini Street",
  town_city: "Caloocan City",
  province: "Metro Manila",
  has_existing_student_number: false,
  program_id: 11,
  year_level: 1,
  student_type: "transferee",
}

const profile = {
  type: "student_profile",
  id: 31,
  user_id: 41,
  student_number: "2027-08-00001",
  name: "Amina S. Santos",
  first_name: input.first_name,
  middle_name: input.middle_name ?? null,
  middle_initial: "S",
  last_name: input.last_name,
  suffix: null,
  email: input.email,
  address: "123 Mabini Street, Caloocan City, Metro Manila",
  gender: input.gender,
  birth_date: input.birth_date,
  citizenship: input.citizenship,
  contact_number: input.contact_number,
  street_barangay: input.street_barangay,
  town_city: input.town_city,
  province: input.province,
  generated_fields: [],
  program_id: input.program_id,
  program_code: "BSIT",
  program_name: "Bachelor of Science in Information Technology",
  curriculum_id: 22,
  entry_year: 2027,
  curriculum_name: "BSIT 2027 Curriculum",
  curriculum_effective_school_year: "2027-2028",
  year_level: input.year_level,
  enrollment_category: "regular",
  student_type: "freshman",
  student_type_label: "Freshman",
  admission_status: "admitted",
  admission_status_label: "Admitted",
  academic_standing: "good",
  academic_standing_label: "Good Standing",
  financial_status: null,
  financial_status_label: null,
  requirements_verified_at: "2026-08-26T08:00:00Z",
  academic_setup_editable: true,
  account_setup_status: "pending",
  invitation_delivery_status: "sent",
  freshman_payment_confirmed_at: null,
  enrollment_deadline_at: "2026-09-02T08:00:00Z",
  freshman_status: null,
  advance_payment_held: false,
  queue_ticket_number: null,
  queue_link_delivery_status: null,
} as const

const page = {
  data: [profile],
  links: {
    first: "http://localhost/api/v1/student-profiles?page=1",
    last: "http://localhost/api/v1/student-profiles?page=1",
    prev: null,
    next: null,
  },
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
}

describe("admission-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("reads the number the next account would get, and null when none is left", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: {
            type: "next_student_number",
            student_number: "2026-10-01936",
          },
        }),
      ),
    )
    await expect(getNextStudentNumber()).resolves.toBe("2026-10-01936")
    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "/api/v1/student-profiles/next-student-number",
    )

    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: { type: "next_student_number", student_number: null },
        }),
      ),
    )
    await expect(getNextStudentNumber()).resolves.toBeNull()
  })

  it("provisions with the student's details and type, without a category, an address text, a password or a curriculum override", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: profile }), { status: 201 }),
    )

    await expect(provisionStudent(input, NOTICE_VERSION)).resolves.toEqual(profile)
    const [url, request] = fetchMock.mock.calls[0] as [string, RequestInit]
    const body =
      typeof request.body === "string"
        ? (JSON.parse(request.body) as Record<string, unknown>)
        : {}

    expect(url).toBe("http://127.0.0.1:8000/api/v1/student-profiles")
    expect(request.method).toBe("POST")
    // What was shown to the applicant travels with the request (ADR 0055): the server refuses it without.
    expect(body).toEqual({
      ...input,
      privacy_notice_acknowledged: true,
      privacy_notice_version: NOTICE_VERSION,
    })
    expect(body).not.toHaveProperty("password")
    expect(body).not.toHaveProperty("curriculum_id")
    expect(body).not.toHaveProperty("entry_year")
    // Admission's own choices travel with the request (stakeholder Doc 20); the Regular / Irregular
    // category is the system's and the single address text is written by the server.
    expect(body).toMatchObject({
      student_type: "transferee",
      gender: "F",
      birth_date: "2006-05-04",
      street_barangay: "123 Mabini Street",
    })
    expect(body).not.toHaveProperty("enrollment_category")
    expect(body).not.toHaveProperty("address")
    // The server assigns the number: none is sent unless the existing-number option is ticked.
    expect(body).not.toHaveProperty("student_number")
  })

  it("reads a freshman's queue number and the delivery of the emailed link", async () => {
    const freshman = {
      ...profile,
      account_setup_status: "awaiting_payment",
      invitation_delivery_status: "not_sent",
      queue_ticket_number: "Q012",
      queue_link_delivery_status: "sent",
    }
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: freshman }), { status: 201 }),
    )

    await expect(provisionStudent(input, NOTICE_VERSION)).resolves.toMatchObject({
      account_setup_status: "awaiting_payment",
      queue_ticket_number: "Q012",
      queue_link_delivery_status: "sent",
    })
  })

  it("reads a profile from a server that predates the queue fields as having no queue number", async () => {
    const older: Record<string, unknown> = { ...profile }
    delete older.queue_ticket_number
    delete older.queue_link_delivery_status
    delete older.freshman_payment_confirmed_at
    delete older.freshman_status
    delete older.advance_payment_held
    delete older.enrollment_deadline_at
    delete older.gender
    delete older.birth_date
    delete older.street_barangay
    delete older.generated_fields
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: older }), { status: 201 }),
    )

    await expect(provisionStudent(input, NOTICE_VERSION)).resolves.toMatchObject({
      queue_ticket_number: null,
      queue_link_delivery_status: null,
      freshman_payment_confirmed_at: null,
      freshman_status: null,
      advance_payment_held: false,
      enrollment_deadline_at: null,
      gender: null,
      birth_date: null,
      street_barangay: null,
      generated_fields: [],
    })
  })

  it("asks the server to send a freshman's queue link again", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: profile })))

    await resendQueueLink(31)

    const [url, request] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(
      "http://127.0.0.1:8000/api/v1/student-profiles/31/queue-link-resends",
    )
    expect(request.method).toBe("POST")
  })

  it("sends the number the student already has when the existing-number option is ticked", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: profile }), { status: 201 }),
    )
    const existing: ProvisionStudentInput = {
      ...input,
      student_type: "returnee",
      has_existing_student_number: true,
      student_number: "2024-06-00123",
    }

    await provisionStudent(existing, NOTICE_VERSION)
    const [, request] = fetchMock.mock.calls[0] as [string, RequestInit]
    const body =
      typeof request.body === "string"
        ? (JSON.parse(request.body) as Record<string, unknown>)
        : {}

    expect(body).toEqual({
      ...existing,
      privacy_notice_acknowledged: true,
      privacy_notice_version: NOTICE_VERSION,
    })
  })

  it("refuses an existing student number for a Freshman before calling the API", async () => {
    for (const studentType of ["freshman"] as const) {
      await expect(
        provisionStudent(
          {
            ...input,
            student_type: studentType,
            has_existing_student_number: true,
            student_number: "2024-06-00123",
          },
          NOTICE_VERSION,
        ),
      ).rejects.toThrow()
    }
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("refuses a student number sent without the existing-number option", async () => {
    await expect(
      provisionStudent({ ...input, student_number: "2024-06-00123" }, NOTICE_VERSION),
    ).rejects.toThrow()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("searches the Admission directory by name, student number, or email", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify(page)))

    await expect(
      listStudentProfiles({ search: "Amina", page: 1, per_page: 20 }),
    ).resolves.toEqual(page)

    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "/api/v1/student-profiles?search=Amina&page=1&per_page=20",
    )
    expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({ method: "GET" })
  })

  it("keeps account setup public and sends Admission decisions to the dedicated endpoint", async () => {
    fetchMock
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({ data: { type: "account-setup", status: "active" } }),
        ),
      )
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            data: {
              type: "student_profile_change_request",
              id: 7,
              student_id: 31,
              student_number: profile.student_number,
              student_name: profile.name,
              status: "approved",
              status_label: "Approved",
              official: {
                name: profile.name,
                first_name: profile.first_name,
                middle_name: profile.middle_name,
                middle_initial: profile.middle_initial,
                last_name: profile.last_name,
                suffix: profile.suffix,
                email: profile.email,
                address: profile.address,
              },
              requested: {
                name: profile.name,
                first_name: profile.first_name,
                middle_name: profile.middle_name,
                middle_initial: profile.middle_initial,
                last_name: profile.last_name,
                suffix: profile.suffix,
                email: profile.email,
                address: profile.address,
              },
              reason: "Correction",
              decision_notes: null,
              identity_verified_at: "2026-08-26T08:10:00Z",
              requested_at: "2026-08-26T08:00:00Z",
              decided_at: "2026-08-26T08:10:00Z",
            },
          }),
        ),
      )

    await expect(
      setupStudentAccount({
        email: profile.email,
        code: "123456",
        password: "New-Secure-Password1!",
        password_confirmation: "New-Secure-Password1!",
      }),
    ).resolves.toBe("active")
    await decideProfileChangeRequest(7, {
      action: "approve",
      identity_verified_in_person: true,
    })

    const setupRequest = fetchMock.mock.calls[0]?.[1]
    const setupHeaders = new Headers(setupRequest?.headers)
    expect(setupHeaders.has("Authorization")).toBe(false)
    expect(fetchMock.mock.calls[1]?.[0]).toContain(
      "/api/v1/student-profile-change-requests/7/decision",
    )
  })

  it("requests student account setup resend without authorization header", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: {
            type: "resend-student-account-setup",
            status: "sent",
            message:
              "If a pending student account exists for this email, a new setup invitation has been sent.",
          },
        }),
      ),
    )

    const response = await requestStudentAccountSetupResend(profile.email)
    expect(response).toEqual({
      type: "resend-student-account-setup",
      status: "sent",
      message:
        "If a pending student account exists for this email, a new setup invitation has been sent.",
    })

    const request = fetchMock.mock.calls[0]?.[1]
    const headers = new Headers(request?.headers)
    expect(headers.has("Authorization")).toBe(false)
    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "/api/v1/auth/resend-student-account-setup",
    )
    expect(JSON.parse(request?.body as string)).toEqual({
      email: profile.email,
    })
  })
})
