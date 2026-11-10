import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { AcademicTermWorkspace } from "@/features/components/portal/academic-term-workspace"
import { renderWithSession } from "@/tests/render-app"

const terms = {
  data: [
    {
      type: "academic-term",
      id: 1,
      school_year: "2022-2023",
      semester: "2nd",
      starts_at: null,
      ends_at: null,
      enrollment_opens_at: null,
      enrollment_closes_at: null,
      add_drop_deadline_at: null,
      grading_deadline_at: null,
      status: "semester_ongoing",
      status_label: "Semester Ongoing",
    },
  ],
} as const

function url(input: RequestInfo | URL) {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

function renderWorkspace() {
  return renderWithSession(<AcademicTermWorkspace />, {
    session: {
      userId: "9",
      displayName: "Registrar Head",
      role: "registrar_head",
      signedInAt: "2026-07-29T12:00:00Z",
    },
  })
}

async function fillDateTime(labelText: string, value: string) {
  const input = screen.getByLabelText(labelText)
  await userEvent.setup().clear(input)
  await userEvent.setup().type(input, value)
}

describe("AcademicTermWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("shows the empty-state form only when no non-archived term exists, and creates the first school year", async () => {
    const user = userEvent.setup()
    const noTerms = { data: [] } as const
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/academic-terms") && init?.method === "POST")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "academic-term",
                id: 2,
                school_year: "2028-2029",
                semester: "1st",
                starts_at: "2028-08-01T00:00:00Z",
                ends_at: "2028-12-15T00:00:00Z",
                enrollment_opens_at: "2028-07-01T00:00:00Z",
                enrollment_closes_at: "2028-07-15T00:00:00Z",
                add_drop_deadline_at: "2028-07-20T00:00:00Z",
                grading_deadline_at: "2028-12-20T00:00:00Z",
                status: "draft",
                status_label: "Draft",
              },
            }),
            { status: 201 },
          ),
        )
      return Promise.resolve(new Response(JSON.stringify(noTerms)))
    })
    renderWorkspace()

    expect(
      await screen.findByText("Start the first school year"),
    ).toBeInTheDocument()

    await user.type(screen.getByLabelText("School year"), "2028-2029")
    await fillDateTime("Enrollment start", "2028-07-01T00:00")
    await fillDateTime("Enrollment deadline", "2028-07-15T00:00")
    await fillDateTime("Add/drop/Change subject deadline", "2028-07-20T00:00")

    await user.click(screen.getByRole("button", { name: "Create school year" }))

    await waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/academic-terms"),
        expect.objectContaining({ method: "POST" }),
      ),
    )
    expect(
      await screen.findByText("Created 2028-2029 · 1st as a Draft term."),
    ).toBeInTheDocument()
  })

  it("hides the empty-state form once a non-archived term exists", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(terms))),
    )
    renderWorkspace()

    await screen.findAllByText("2022-2023 · 2nd")
    expect(
      screen.queryByText("Start the first school year"),
    ).not.toBeInTheDocument()
    expect(screen.queryByLabelText("School year")).not.toBeInTheDocument()
  })

  it("shows the existing academic terms table", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(terms))),
    )
    renderWorkspace()

    expect(
      (await screen.findAllByText("2022-2023 · 2nd")).length,
    ).toBeGreaterThan(0)
    expect(
      (await screen.findAllByText("Semester Ongoing")).length,
    ).toBeGreaterThan(0)
    const termCard = screen.getByRole("article", {
      name: "2022-2023 · 2nd enrollment cycle",
    })
    expect(within(termCard).getByText("Semester Ongoing")).toBeInTheDocument()
  })

  it("offers archive as the only lifecycle action for an ongoing semester", async () => {
    renderWorkspace()
    await screen.findAllByText("2022-2023 · 2nd")

    expect(
      screen.queryByRole("button", { name: /^Close/i }),
    ).not.toBeInTheDocument()
    expect(
      screen.getAllByRole("button", { name: /Archive/i }).length,
    ).toBeGreaterThan(0)
  })

  it("does not display Edit draft term button in the terms table (Doc 3 requirement)", async () => {
    const draftTerms = {
      data: [
        {
          type: "academic-term" as const,
          id: 2,
          school_year: "2026-2028",
          semester: "2nd",
          starts_at: null,
          ends_at: null,
          enrollment_opens_at: "2026-07-01T08:00:00Z",
          enrollment_closes_at: "2026-07-15T17:00:00Z",
          add_drop_deadline_at: "2026-07-20T17:00:00Z",
          grading_deadline_at: null,
          status: "draft" as const,
          status_label: "Draft",
          is_actionable_current: true,
        },
      ],
    }
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(draftTerms))),
    )
    renderWorkspace()

    expect(
      (await screen.findAllByText("2026-2028 · 2nd")).length,
    ).toBeGreaterThan(0)
    expect(
      screen.queryByRole("button", { name: "Edit draft term" }),
    ).not.toBeInTheDocument()
  })

  it("archives the current term and automatically opens the next sequential term through the dialog", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      if (
        url(input).includes("/academic-terms/1/archive-and-create-next") &&
        init?.method === "POST"
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "academic-term",
                id: 2,
                school_year: "2023-2024",
                semester: "1st",
                starts_at: null,
                ends_at: null,
                enrollment_opens_at: null,
                enrollment_closes_at: null,
                add_drop_deadline_at: null,
                grading_deadline_at: null,
                status: "draft",
                status_label: "Draft",
              },
            }),
            { status: 201 },
          ),
        )
      return Promise.resolve(new Response(JSON.stringify(terms)))
    })
    renderWorkspace()

    await user.click(
      await screen.findByRole("button", { name: "Archive current semester" }),
    )
    expect(
      await screen.findByText(/Archive 2022-2023 · 2nd/),
    ).toBeInTheDocument()
    expect(
      await screen.findByText("2023-2024 · 1st Semester"),
    ).toBeInTheDocument()

    await user.click(
      screen.getByRole("button", { name: "Archive and open 2023-2024 · 1st Sem" }),
    )

    await waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/academic-terms/1/archive-and-create-next"),
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({ school_year: "2023-2024", semester: "1st" }),
        }),
      ),
    )
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(terms))),
    )
    const { container } = renderWorkspace()

    await screen.findAllByText("2022-2023 · 2nd")
    expect(await axe(container)).toHaveNoViolations()
  })
})
