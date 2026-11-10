import { screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { EnrollmentStatusStudentsDialog } from "@/features/components/portal/enrollment-status-students-dialog"
import { renderWithSession } from "@/tests/render-app"

const sampleEnrollment = {
  type: "enrollment",
  id: 101,
  student_id: 1,
  student_number: "2026-0001",
  student_name: "Jane Dela Cruz",
  student_year_level: 2,
  student_financial_status: "scholar",
  student_financial_status_label: "Scholar",
  academic_term_id: 2,
  status: "pending_registrar_approval",
  status_label: "Pending registrar approval",
  total_units: 18,
  requires_overload_approval: false,
  program_head_comment: null,
  submitted_at: "2026-08-01T08:30:00Z",
  program_head_decided_at: null,
  registrar_decided_at: null,
  payment_confirmed_at: null,
  enrolled_at: null,
  subjects: [],
  queue_ticket: null,
  assessment: null,
}

const paginationLinks = {
  first: "http://api.test/enrollments?page=1",
  last: "http://api.test/enrollments?page=1",
  prev: null,
  next: null,
}

const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 1,
}

describe("EnrollmentStatusStudentsDialog", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("renders student roster with student number, name, ordinal year level, and units", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [sampleEnrollment],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )

    renderWithSession(
      <EnrollmentStatusStudentsDialog
        open={true}
        onOpenChange={vi.fn()}
        status="pending_registrar_approval"
        statusLabel="Pending Registrar Approval"
        academicTermId={2}
        academicTermLabel="2026-2027 · 1st Semester"
      />,
    )

    expect(
      await screen.findByRole("dialog", {
        name: "Students · Pending Registrar Approval",
      }),
    ).toBeInTheDocument()

    const studentNumbers = await screen.findAllByText("2026-0001")
    expect(studentNumbers.length).toBeGreaterThan(0)
    expect(screen.getAllByText("Jane Dela Cruz").length).toBeGreaterThan(0)
    expect(screen.getAllByText("2ND YEAR").length).toBeGreaterThan(0)
    expect(screen.getAllByText("18 units").length).toBeGreaterThan(0)
    expect(screen.getByText("1 total")).toBeInTheDocument()
  })

  it("renders an empty state when no students have this status", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [],
          links: paginationLinks,
          meta: { ...paginationMeta, total: 0 },
        }),
      ),
    )

    renderWithSession(
      <EnrollmentStatusStudentsDialog
        open={true}
        onOpenChange={vi.fn()}
        status="rejected"
        statusLabel="Rejected"
        academicTermId={2}
        academicTermLabel="2026-2027 · 1st Semester"
      />,
    )

    expect(
      await screen.findByText(/No students found with status/i),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [sampleEnrollment],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )

    const { container } = renderWithSession(
      <EnrollmentStatusStudentsDialog
        open={true}
        onOpenChange={vi.fn()}
        status="pending_registrar_approval"
        statusLabel="Pending Registrar Approval"
      />,
    )

    const studentNumbers = await screen.findAllByText("2026-0001")
    expect(studentNumbers.length).toBeGreaterThan(0)
    expect(await axe(container)).toHaveNoViolations()
  })
})
