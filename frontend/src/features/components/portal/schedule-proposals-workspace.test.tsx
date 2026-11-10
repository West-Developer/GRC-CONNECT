import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ScheduleProposalsWorkspace } from "@/features/components/portal/schedule-proposals-workspace"
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
const proposals = { data: [] }
const draft = {
  data: {
    type: "schedule_proposal",
    id: 9,
    academic_term_id: 2,
    submitted_by: 4,
    status: "draft",
    status_label: "Draft",
    decided_by: null,
    decided_at: null,
    decision_reason: null,
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

describe("ScheduleProposalsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("creates only a draft proposal and refreshes the proposal list", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/academic-terms")
              ? terms
              : init?.method === "POST"
                ? draft
                : proposals,
          ),
          { status: init?.method === "POST" ? 201 : 200 },
        ),
      ),
    )
    renderWithSession(<ScheduleProposalsWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await waitForActiveTermSelected()
    await user.click(
      screen.getByRole("button", { name: "Create draft proposal" }),
    )
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/schedule-proposals"),
      expect.objectContaining({ method: "POST" }),
    )
    expect(
      screen.queryByRole("button", { name: /approve|publish/i }),
    ).not.toBeInTheDocument()
  })

  it("shows duplicate-term proposal validation returned by the API", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) =>
      Promise.resolve(
        init?.method === "POST"
          ? new Response(
              JSON.stringify({
                error: {
                  code: "VALIDATION_FAILED",
                  message: "Duplicate proposal",
                  errors: {
                    academic_term_id: [
                      "This term already has an active proposal.",
                    ],
                  },
                  request_id: "req-8",
                },
              }),
              { status: 422 },
            )
          : new Response(
              JSON.stringify(
                url(input).endsWith("/academic-terms") ? terms : proposals,
              ),
            ),
      ),
    )
    renderWithSession(<ScheduleProposalsWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await waitForActiveTermSelected()
    await user.click(
      screen.getByRole("button", { name: "Create draft proposal" }),
    )
    expect(
      await screen.findByText("This term already has an active proposal."),
    ).toBeInTheDocument()
  })

  it("retries failed reference data in place", async () => {
    const user = userEvent.setup()
    let termAttempts = 0
    fetchMock.mockImplementation((input) => {
      if (url(input).endsWith("/academic-terms")) {
        termAttempts += 1
        return Promise.resolve(
          termAttempts <= 2
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
      return Promise.resolve(new Response(JSON.stringify(proposals)))
    })
    renderWithSession(<ScheduleProposalsWorkspace />, {
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
    expect(termAttempts).toBe(3)
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/academic-terms") ? terms : proposals,
          ),
        ),
      ),
    )
    const { container } = renderWithSession(<ScheduleProposalsWorkspace />, {
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
