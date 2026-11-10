import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { SectionsWorkspace } from "@/features/components/portal/sections-workspace"
import { renderWithSession } from "@/tests/render-app"

const terms = {
  data: [
    {
      type: "academic-term",
      id: 2,
      school_year: "2026-2027",
      semester: "1st",
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
}
const twoTerms = {
  data: [
    ...terms.data,
    {
      type: "academic-term",
      id: 3,
      school_year: "2026-2027",
      semester: "2nd",
      starts_at: null,
      ends_at: null,
      enrollment_opens_at: null,
      enrollment_closes_at: null,
      add_drop_deadline_at: null,
      grading_deadline_at: null,
      status: "draft",
      status_label: "Draft",
    },
  ],
}
const subjects = {
  data: [
    {
      type: "subject",
      id: 7,
      code: "CS101",
      title: "Programming 1",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
  ],
}
const sections = { data: [] }
const created = {
  data: {
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
    viability_threshold: 25,
    enrolled_count: 0,
    remaining_seats: 30,
    is_block_exclusive: null,
    status: "planned",
    status_label: "Planned",
  },
}

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

/** Waits for the term reference data to load and the active-term default to apply. */
async function waitForActiveTermSelected() {
  await waitFor(() => {
    expect(screen.getByLabelText("Academic term")).toHaveTextContent(
      "2026-2027 · 1st",
    )
  })
}

/** Opens a `Select` trigger and picks an item. */
async function selectOption(
  user: ReturnType<typeof userEvent.setup>,
  labelText: string,
  optionName: string,
) {
  await user.click(screen.getByLabelText(labelText))
  await user.click(await screen.findByRole("option", { name: optionName }))
}

describe("SectionsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("validates schedule time and capacity before creating a planned section", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/academic-terms")
              ? terms
              : url(input).endsWith("/subjects")
                ? subjects
                : init?.method === "POST"
                  ? created
                  : sections,
          ),
          { status: init?.method === "POST" ? 201 : 200 },
        ),
      ),
    )
    renderWithSession(<SectionsWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await waitForActiveTermSelected()
    await selectOption(user, "Subject", "CS101 — Programming 1")
    await user.type(screen.getByLabelText("Section code"), "A")
    await user.clear(screen.getByLabelText("Capacity"))
    await user.type(screen.getByLabelText("Capacity"), "0")
    await user.click(screen.getByRole("button", { name: "Save section" }))
    expect(
      await screen.findByText("Capacity must be at least 1."),
    ).toBeInTheDocument()
    expect(
      fetchMock.mock.calls.some(([, init]) => init?.method === "POST"),
    ).toBe(false)
  })

  it("creates a section for the selected academic term and refreshes the plan", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/academic-terms")
              ? terms
              : url(input).endsWith("/subjects")
                ? subjects
                : init?.method === "POST"
                  ? created
                  : sections,
          ),
          { status: init?.method === "POST" ? 201 : 200 },
        ),
      ),
    )
    renderWithSession(<SectionsWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await waitForActiveTermSelected()
    await selectOption(user, "Subject", "CS101 — Programming 1")
    await user.type(screen.getByLabelText("Section code"), "A")
    await user.clear(screen.getByLabelText("Capacity"))
    await user.type(screen.getByLabelText("Capacity"), "30")
    await user.click(screen.getByRole("button", { name: "Save section" }))
    await vi.waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/sections"),
        expect.objectContaining({ method: "POST" }),
      ),
    )
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(
      fetchMock.mock.calls.filter(([request]) =>
        url(request).endsWith("/sections"),
      ),
    ).toHaveLength(3)
  })

  it("keeps an explicitly selected second term in the created section payload", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/academic-terms")
              ? twoTerms
              : url(input).endsWith("/subjects")
                ? subjects
                : init?.method === "POST"
                  ? { data: { ...created.data, academic_term_id: 3 } }
                  : sections,
          ),
          { status: init?.method === "POST" ? 201 : 200 },
        ),
      ),
    )
    renderWithSession(<SectionsWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await waitForActiveTermSelected()
    await selectOption(user, "Academic term", "2026-2027 · 2nd")
    await selectOption(user, "Subject", "CS101 — Programming 1")
    await user.type(screen.getByLabelText("Section code"), "B")
    await user.clear(screen.getByLabelText("Capacity"))
    await user.type(screen.getByLabelText("Capacity"), "30")
    await user.click(screen.getByRole("button", { name: "Save section" }))
    await vi.waitFor(() => {
      const request = fetchMock.mock.calls.find(
        ([request, init]) =>
          url(request).endsWith("/sections") && init?.method === "POST",
      )
      expect(JSON.parse(request?.[1]?.body as string)).toEqual(
        expect.objectContaining({ academic_term_id: 3, section_code: "B" }),
      )
    })
  })

  it("retries failed reference data in place", async () => {
    const user = userEvent.setup()
    let termsAttempts = 0
    fetchMock.mockImplementation((input) => {
      if (url(input).endsWith("/academic-terms")) {
        termsAttempts += 1
        return Promise.resolve(
          termsAttempts <= 2
            ? new Response(
                JSON.stringify({
                  error: {
                    code: "UNAVAILABLE",
                    message: "Unavailable",
                    errors: {},
                    request_id: "req-retry",
                  },
                }),
                { status: 500 },
              )
            : new Response(JSON.stringify(terms)),
        )
      }
      return Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/subjects") ? subjects : sections,
          ),
        ),
      )
    })
    renderWithSession(<SectionsWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await screen.findByText(/Unavailable/, {}, { timeout: 3_000 })
    await user.click(screen.getByRole("button", { name: "Try again" }))
    await waitForActiveTermSelected()
    expect(termsAttempts).toBe(3)
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/academic-terms")
              ? terms
              : url(input).endsWith("/subjects")
                ? subjects
                : sections,
          ),
        ),
      ),
    )
    const { container } = renderWithSession(<SectionsWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await waitForActiveTermSelected()
    expect(await axe(container)).toHaveNoViolations()
  })
})
