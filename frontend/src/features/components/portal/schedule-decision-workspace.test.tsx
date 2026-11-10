import { act, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ScheduleDecisionWorkspace } from "@/features/components/portal/schedule-decision-workspace"
import { sectionsQueryKey } from "@/features/hooks/use-reference-data"
import { scheduleProposalsQueryKey } from "@/features/hooks/use-scheduling"
import { availableScheduleActions } from "@/features/services/scheduling-service"
import { renderWithSession } from "@/tests/render-app"

const draftProposal = {
  type: "schedule_proposal",
  id: 9,
  academic_term_id: 2,
  submitted_by: 4,
  submitted_by_name: "CCS Program Chair",
  college: "ccs",
  college_label: "College of Computer Studies",
  academic_term_label: "2026-2027 · 1st",
  is_submitted: true,
  status: "draft",
  status_label: "Draft",
  decided_by: null,
  decided_at: null,
  decision_reason: null,
} as const
const deanApprovedProposal = {
  ...draftProposal,
  status: "dean_approved",
  status_label: "Dean approved",
} as const
const publishedProposal = {
  ...draftProposal,
  status: "published",
  status_label: "Published",
} as const
const proposals = {
  data: [draftProposal, deanApprovedProposal, publishedProposal],
}
const reviewSections = {
  data: [{
    type: "schedule_review_section",
    id: 87,
    section_code: "IT101",
    subject_code: "PSPEAK",
    subject_title: "Public Speaking",
    units: 3,
    professor_id: 43,
    professor_name: "ALONZO",
    schedule_days: "T",
    starts_at_time: "13:17:00",
    ends_at_time: "14:15:00",
    room: "5F",
    modality: "f2f",
  }],
}

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

describe("ScheduleDecisionWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it("exposes only API-legal actions for each decision role and status", () => {
    expect(availableScheduleActions("dean", draftProposal)).toEqual([
      "dean_approve",
      "dean_return",
    ])
    expect(
      availableScheduleActions("executive_director", deanApprovedProposal),
    ).toEqual(["publish", "executive_return"])
    expect(
      availableScheduleActions("registrar_head", publishedProposal),
    ).toEqual(["close"])
    for (const role of [
      "student",
      "admission_staff",
      "faculty",
      "program_chair",
      "dean",
      "executive_director",
      "registrar_head",
      "registrar_staff",
      "accounting_staff",
    ] as const) {
      for (const proposal of [
        draftProposal,
        deanApprovedProposal,
        { ...draftProposal, status: "executive_approved" as const },
        publishedProposal,
        { ...draftProposal, status: "closed" as const },
      ]) {
        const expected =
          role === "dean" && proposal.status === "draft"
            ? ["dean_approve", "dean_return"]
            : role === "dean" && proposal.status === "dean_approved"
              ? []
              : role === "executive_director" &&
                  proposal.status === "dean_approved"
                ? ["publish", "executive_return"]
                : role === "registrar_head" && proposal.status === "published"
                    ? ["close"]
                    : []
        expect(availableScheduleActions(role, proposal)).toEqual(expected)
      }
    }
  })

  it("requires a return reason and sends one confirmed patch only while pending", async () => {
    const user = userEvent.setup()
    let resolvePatch: ((response: Response) => void) | undefined
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH")
        return new Promise<Response>((resolve) => {
          resolvePatch = resolve
        })
      return Promise.resolve(new Response(JSON.stringify({ data: [draftProposal] })))
    })
    renderWithSession(<ScheduleDecisionWorkspace />, {
      session: {
        userId: "5",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await user.click(
      await screen.findByRole("button", { name: "Return with notes" }),
    )
    expect(
      screen.getByRole("button", { name: "Confirm decision" }),
    ).toBeDisabled()
    await user.type(
      screen.getByLabelText("Notes for Program Head"),
      "Capacity conflict",
    )
    await user.click(screen.getByRole("button", { name: "Confirm decision" }))
    expect(
      screen.getByRole("button", { name: "Saving decision" }),
    ).toBeDisabled()
    await user.click(screen.getByRole("button", { name: "Saving decision" }))
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock).toHaveBeenLastCalledWith(
      expect.stringContaining("/schedule-proposals/9"),
      expect.objectContaining({
        method: "PATCH",
        body: JSON.stringify({
          action: "dean_return",
          decision_reason: "Capacity conflict",
        }),
      }),
    )
    resolvePatch?.(new Response(JSON.stringify({ data: draftProposal })))
  })

  it("shows the submitting department and opens its submitted schedule", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) => Promise.resolve(new Response(JSON.stringify(
      url(input).includes("/schedule-proposals/9/sections") ? reviewSections : { data: [draftProposal] },
    ))))
    renderWithSession(<ScheduleDecisionWorkspace />, {
      session: {
        userId: "5",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    expect(await screen.findByText("College of Computer Studies")).toBeInTheDocument()
    expect(screen.getByText("2026-2027 · 1st")).toBeInTheDocument()
    expect(screen.getByText("Submitted by CCS Program Chair")).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Review schedule" }))
    expect(await screen.findByRole("dialog", { name: "Review schedule · College of Computer Studies" })).toBeInTheDocument()
    const it101Card = await screen.findByRole("article", { name: "IT101 section" })
    expect(it101Card).toBeInTheDocument()
    await user.click(within(it101Card).getByRole("button", { name: "View schedule" }))

    expect(await screen.findByRole("dialog", { name: /IT101 Schedule/i })).toBeInTheDocument()
    expect(screen.getByText("Public Speaking")).toBeInTheDocument()
    expect(screen.getByText("ALONZO")).toBeInTheDocument()
    expect(screen.getByText("5F")).toBeInTheDocument()
  })

  it("invalidates both proposal and section caches after a successful transition", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH")
        return Promise.resolve(
          new Response(JSON.stringify({ data: deanApprovedProposal })),
        )
      return Promise.resolve(new Response(JSON.stringify(proposals)))
    })
    const { queryClient } = renderWithSession(<ScheduleDecisionWorkspace />, {
      session: {
        userId: "5",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    const invalidateSpy = vi.spyOn(queryClient, "invalidateQueries")
    await user.click(
      await screen.findByRole("button", { name: "Approve schedule" }),
    )
    await user.click(screen.getByRole("button", { name: "Confirm decision" }))
    await screen.findByRole("button", { name: "Approve schedule" })
    expect(invalidateSpy).toHaveBeenCalledWith({
      queryKey: scheduleProposalsQueryKey("5"),
      exact: true,
    })
    expect(invalidateSpy).toHaveBeenCalledWith({
      queryKey: sectionsQueryKey("5"),
      exact: true,
    })
  })

  it("requires an explicit confirmation before a dean transition", async () => {
    const user = userEvent.setup()
    fetchMock.mockResolvedValue(new Response(JSON.stringify(proposals)))
    renderWithSession(<ScheduleDecisionWorkspace />, {
      session: {
        userId: "5",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await user.click(
      await screen.findByRole("button", { name: "Approve schedule" }),
    )
    expect(screen.getByRole("alertdialog")).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it("does not render a decision workspace for an unauthorized role", () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify(proposals)))
    renderWithSession(<ScheduleDecisionWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })

  it("refreshes a Dean queue when a Program Chair submits without a page reload", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let proposalSubmitted = false
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify({ data: proposalSubmitted ? [draftProposal] : [] }),
        ),
      ),
    )

    renderWithSession(<ScheduleDecisionWorkspace />, {
      session: {
        userId: "5",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    expect(
      await screen.findByText("No schedule decisions are currently available."),
    ).toBeInTheDocument()

    proposalSubmitted = true
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30_000)
    })

    expect(
      await screen.findByRole("button", { name: "Approve schedule" }),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify(proposals)))
    const { container } = renderWithSession(<ScheduleDecisionWorkspace />, {
      session: {
        userId: "5",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await screen.findByRole("button", { name: "Approve schedule" })
    expect(await axe(container)).toHaveNoViolations()
  })
})
