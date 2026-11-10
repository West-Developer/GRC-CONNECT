import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { RegistrarSubjectWithdrawalsWorkspace } from "@/features/components/portal/registrar-subject-withdrawals-workspace"
import { renderWithSession } from "@/tests/render-app"

const student = {
  type: "academic_record_student",
  id: 7,
  student_id: 7,
  student_number: "2026-0007",
  name: "Juan Dela Cruz",
  first_name: "Juan",
  last_name: "Dela Cruz",
  email: "juan@grc.test",
  program_code: "BSCS",
  program_name: "BS Computer Science",
  year_level: 1,
  enrollment_category: "regular",
  enrollment_category_label: "Regular",
  academic_standing: "good",
  academic_standing_label: "Good",
}

function overview(withdrawn: boolean) {
  return {
    data: {
      withdrawable: withdrawn
        ? []
        : [
            {
              enrollment_subject_id: 11,
              enrollment_id: 3,
              academic_term_id: 1,
              term_label: "2026-2027 · 1st",
              subject_code: "CS101",
              subject_title: "Programming 1",
              units: 3,
              section_code: "CS101-A",
            },
          ],
      withdrawn: withdrawn
        ? [
            {
              academic_grade_id: 5,
              term_label: "2026-2027 · 1st",
              subject_code: "CS101",
              subject_title: "Programming 1",
              section_code: "CS101-A",
              reason: "Left after the midterm.",
              withdrawn_by: "Registrar Head",
              withdrawn_at: "2026-10-22T01:00:00Z",
            },
          ]
        : [],
    },
  }
}

function urlOf(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

function session(role: "registrar_head" | "registrar_staff") {
  return {
    session: {
      userId: "1",
      displayName: "Someone",
      role,
      signedInAt: "2026-10-22T00:00:00Z",
    },
  }
}

describe("RegistrarSubjectWithdrawalsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let withdrawn = false
  let posted: unknown = null

  beforeEach(() => {
    withdrawn = false
    posted = null
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input, init) => {
      const url = urlOf(input)
      if (url.includes("/academic-record/students")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: [student] })),
        )
      }
      if (url.includes("/subject-withdrawals")) {
        if (init?.method === "POST") {
          posted = JSON.parse(String(init.body))
          withdrawn = true
        }
        return Promise.resolve(
          new Response(JSON.stringify(overview(withdrawn))),
        )
      }
      return Promise.resolve(new Response("{}", { status: 404 }))
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  async function pickStudent(user: ReturnType<typeof userEvent.setup>) {
    await user.type(screen.getByLabelText("Student number or name"), "Juan")
    await user.click(screen.getByRole("button", { name: "Search" }))
    await user.click(
      await screen.findByRole("button", { name: /Juan Dela Cruz/ }),
    )
  }

  it("is not available to anyone but the Registrar Head", () => {
    renderWithSession(
      <RegistrarSubjectWithdrawalsWorkspace />,
      session("registrar_staff"),
    )

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(screen.queryByLabelText("Student number or name")).toBeNull()
  })

  it("withdraws the ticked subject with a reason and lists it as withdrawn", async () => {
    const user = userEvent.setup()
    renderWithSession(
      <RegistrarSubjectWithdrawalsWorkspace />,
      session("registrar_head"),
    )
    await pickStudent(user)

    const withdrawButton = await screen.findByRole("button", {
      name: /Withdraw .*selected subject/,
    })
    expect(withdrawButton).toBeDisabled()

    await user.click(screen.getByRole("checkbox", { name: /CS101/ }))
    await user.type(
      screen.getByLabelText("Reason for the withdrawal"),
      "Left after the midterm.",
    )
    await user.click(
      screen.getByRole("button", { name: /Withdraw 1 selected subject/ }),
    )
    await user.click(
      await screen.findByRole("button", { name: "Confirm withdrawal" }),
    )

    await waitFor(() =>
      expect(posted).toEqual({
        enrollment_subject_ids: [11],
        reason: "Left after the midterm.",
      }),
    )
    expect(await screen.findByText("W · Withdrawn")).toBeInTheDocument()
    expect(screen.getByText(/Left after the midterm\./)).toBeInTheDocument()
  })

  it("has no accessibility violations once a student is chosen", async () => {
    const user = userEvent.setup()
    const { container } = renderWithSession(
      <RegistrarSubjectWithdrawalsWorkspace />,
      session("registrar_head"),
    )
    await pickStudent(user)
    await screen.findByRole("checkbox", { name: /CS101/ })

    expect(await axe(container)).toHaveNoViolations()
  })
})
