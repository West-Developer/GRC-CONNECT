import { screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { GraduatesWorkspace } from "@/features/components/portal/graduates-workspace"
import { renderWithSession } from "@/tests/render-app"

const mockGraduatesResponse = {
  data: [
    {
      id: 1,
      student_number: "2018-00001",
      full_name: "DELA CRUZ, JUAN A.",
      first_name: "JUAN",
      last_name: "DELA CRUZ",
      email: "juan.delacruz@grc.test",
      program_id: 1,
      program_code: "BSIT",
      program_name: "BS Information Technology",
      college: "ccs",
      curriculum_id: 1,
      curriculum_name: "BSIT Curriculum 2018-2023",
      curriculum_version: "2018-2023",
      entry_year: 2018,
      graduation_school_year: "2021-2022",
      final_gpa: 1.50,
    },
  ],
  summary: {
    total_graduates: 1,
  },
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 1,
  },
}

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

describe("GraduatesWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("renders the graduates list and passes accessibility checks", async () => {
    fetchMock.mockImplementation((input) => {
      const u = url(input)
      if (u.includes("graduates")) {
        return Promise.resolve(
          new Response(JSON.stringify(mockGraduatesResponse)),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    const view = renderWithSession(<GraduatesWorkspace />, {
      session: {
        userId: "1",
        displayName: "Registrar Head",
        role: "registrar_head",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    expect(
      view.getByRole("region", { name: "Graduates directory" }),
    ).toBeInTheDocument()

    expect(await screen.findByText("DELA CRUZ, JUAN A.")).toBeInTheDocument()
    expect(screen.getByText("2018-00001")).toBeInTheDocument()
    expect(screen.getByText("BSIT")).toBeInTheDocument()
    expect(screen.getByText("SY 2021-2022")).toBeInTheDocument()
    expect(screen.getByText("1.50")).toBeInTheDocument()

    const results = await axe(view.container)
    expect(results).toHaveNoViolations()
  })

  it("renders unauthorized state for students", async () => {
    renderWithSession(<GraduatesWorkspace />, {
      session: {
        userId: "2",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })
})
