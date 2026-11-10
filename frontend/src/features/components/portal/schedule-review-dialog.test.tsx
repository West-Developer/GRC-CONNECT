import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ScheduleReviewDialog } from "@/features/components/portal/schedule-review-dialog"
import type { ScheduleProposal } from "@/features/schemas/scheduling-schema"
import { renderWithSession } from "@/tests/render-app"

const proposal: ScheduleProposal = {
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
  status_label: "Pending Dean review",
  decided_by: null,
  decided_at: null,
  decision_reason: null,
  decision_history: [],
}

const reviewSections = {
  data: [
    {
      type: "schedule_review_section",
      id: 41,
      section_code: "IT101",
      subject_code: "PROG1",
      subject_title: "Programming 1",
      units: 2,
      professor_id: 43,
      professor_name: "SANTOS",
      schedule_days: "M",
      starts_at_time: "08:00:00",
      ends_at_time: "09:30:00",
      room: "LAB 1",
      modality: "f2f",
    },
    {
      type: "schedule_review_section",
      id: 42,
      section_code: "IT101",
      subject_code: "PROG1L",
      subject_title: "Programming 1 LAB",
      units: 1,
      professor_id: null,
      professor_name: null,
      schedule_days: null,
      starts_at_time: null,
      ends_at_time: null,
      room: null,
      modality: null,
    },
    {
      type: "schedule_review_section",
      id: 57,
      section_code: "IT201",
      subject_code: "DSTRUCT",
      subject_title: "Data Structures",
      units: 3,
      professor_id: 45,
      professor_name: "REYES",
      schedule_days: "T",
      starts_at_time: "10:00:00",
      ends_at_time: "11:30:00",
      room: "5A",
      modality: "hyflex_a",
    },
  ],
}

function renderDialog(actorRole: "dean" | "executive_director" = "dean") {
  return renderWithSession(
    <ScheduleReviewDialog
      actorRole={actorRole}
      proposal={
        actorRole === "executive_director"
          ? { ...proposal, status: "dean_approved", status_label: "Dean approved" }
          : proposal
      }
      decisionPending={false}
      onOpenChange={vi.fn()}
      onDecision={vi.fn()}
    />,
    {
      session: {
        userId: actorRole === "dean" ? "5" : "6",
        displayName: actorRole === "dean" ? "Dean" : "Executive Director",
        role: actorRole,
        signedInAt: "2026-08-02T00:00:00Z",
      },
    },
  )
}

describe("ScheduleReviewDialog", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockResolvedValue(new Response(JSON.stringify(reviewSections)))
  })

  afterEach(() => vi.unstubAllGlobals())

  it("groups subjects by block, supports Calendar and Table view toggle, and shows one section at a time", async () => {
    const user = userEvent.setup()
    renderDialog()

    expect(await screen.findByText("2 block sections")).toBeInTheDocument()
    expect(screen.getByText("3 subject schedules")).toBeInTheDocument()

    const it101Card = screen.getByRole("article", { name: "IT101 section" })
    const it201Card = screen.getByRole("article", { name: "IT201 section" })
    expect(it101Card).toBeInTheDocument()
    expect(it201Card).toBeInTheDocument()

    // Click "View schedule" on IT101 card to open the schedule modal
    await user.click(within(it101Card).getByRole("button", { name: "View schedule" }))

    expect(screen.getByText("Programming 1")).toBeInTheDocument()
    expect(screen.queryByText("Data Structures")).not.toBeInTheDocument()

    // Switch to Calendar view
    await user.click(screen.getByRole("radio", { name: "Calendar view" }))

    // Switch back to Table view
    await user.click(screen.getByRole("radio", { name: "Table view" }))
    expect(screen.getAllByText("Unassigned")).not.toHaveLength(0)

    // Close the IT101 modal
    const scheduleModal = screen.getByRole("dialog", { name: /IT101 Schedule/i })
    await user.click(within(scheduleModal).getAllByRole("button", { name: "Close" })[0])

    // Open IT201 modal
    await user.click(within(it201Card).getByRole("button", { name: "View schedule" }))
    expect(screen.getByText("Data Structures")).toBeInTheDocument()
    expect(screen.queryByText("Programming 1 LAB")).not.toBeInTheDocument()
  })

  it("keeps the dialog inside the viewport and exposes Dean actions", async () => {
    renderDialog()

    const dialog = await screen.findByRole("dialog", {
      name: /College of Computer Studies/,
    })
    expect(dialog).toHaveClass(
      "max-h-[100dvh]",
      "sm:max-h-[90dvh]",
      "overflow-hidden",
    )
    expect(screen.getByRole("button", { name: "Return with notes" })).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Approve schedule" })).toBeInTheDocument()
  })

  it("lets the Executive Director publish directly or return with notes", async () => {
    renderDialog("executive_director")

    expect(
      await screen.findByRole("button", { name: "Publish schedule" }),
    ).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Return with notes" })).toBeInTheDocument()
  })

  it("has no detectable accessibility violations after the schedule loads", async () => {
    const { container } = renderDialog()
    await screen.findByRole("article", { name: "IT101 section" })
    expect(await axe(container)).toHaveNoViolations()
  })
})
