import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import {
  SectionScheduleCalendar,
  type SectionScheduleItem,
} from "@/features/components/portal/section-schedule-calendar"
import { SectionScheduleCalendarDialog } from "@/features/components/portal/section-schedule-calendar-dialog"

const sampleItems: SectionScheduleItem[] = [
  {
    id: 101,
    subject_code: "IT101",
    subject_title: "Introduction to Computing",
    units: 3,
    room: "3F",
    professor_name: "Danilo Portiles",
    schedule_days: "TUE",
    starts_at_time: "07:30:00",
    ends_at_time: "09:30:00",
    modality: "hyflex_a",
  },
  {
    id: 102,
    subject_code: "IT101L",
    subject_title: "Introduction to Computing LAB",
    units: 1,
    room: "LAB2",
    professor_name: "Danilo Portiles",
    schedule_days: "WED",
    starts_at_time: "07:30:00",
    ends_at_time: "10:30:00",
    modality: "f2f",
  },
  {
    id: 103,
    subject_code: "ITASYNC",
    subject_title: "Asynchronous Subject",
    units: 2,
    room: null,
    professor_name: "Online Prof",
    schedule_days: null,
    starts_at_time: null,
    ends_at_time: null,
    modality: "online" as unknown as null,
  },
]

describe("SectionScheduleCalendar", () => {
  it("renders the weekly calendar grid with scheduled subjects", () => {
    render(<SectionScheduleCalendar items={sampleItems} />)

    expect(screen.getByText("IT101")).toBeInTheDocument()
    expect(screen.getByText("IT101L")).toBeInTheDocument()
    expect(screen.getAllByText("Danilo Portiles").length).toBeGreaterThan(0)
    expect(screen.getByText("3F")).toBeInTheDocument()
    expect(screen.getByText("LAB2")).toBeInTheDocument()
    expect(screen.getByText("7:30 AM–9:30 AM")).toBeInTheDocument()
    expect(screen.getByText("7:30 AM–10:30 AM")).toBeInTheDocument()
  })

  it("renders asynchronous / unscheduled subjects in the bottom tray", () => {
    render(<SectionScheduleCalendar items={sampleItems} />)

    expect(
      screen.getByText(/Asynchronous & Unscheduled Subjects/i),
    ).toBeInTheDocument()
    expect(screen.getByText("ITASYNC")).toBeInTheDocument()
    expect(screen.getByText("Asynchronous Subject")).toBeInTheDocument()
  })

  it("calls onSelectSubject when a subject block is clicked", async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()
    render(
      <SectionScheduleCalendar
        items={sampleItems}
        onSelectSubject={onSelect}
      />,
    )

    const buttons = screen.getAllByRole("button", {
      name: /click to edit assignment/i,
    })
    await user.click(buttons[0])

    expect(onSelect).toHaveBeenCalledWith(
      expect.objectContaining({
        id: 101,
        subject_code: "IT101",
      }),
    )
  })

  it("has no detectable accessibility violations", async () => {
    const { container } = render(
      <SectionScheduleCalendar items={sampleItems} />,
    )

    expect(await axe(container)).toHaveNoViolations()
  })
})

describe("SectionScheduleCalendar on a phone", () => {
  // Under Tailwind's `md` (48rem) the 58rem-wide weekly grid cannot fit, so it
  // is replaced by a day-at-a-time agenda (stakeholder Doc 13).
  beforeEach(() => {
    vi.spyOn(window, "matchMedia").mockImplementation(
      (query: string) =>
        ({
          matches: query.includes("max-width: 47.99rem"),
          media: query,
          onchange: null,
          addListener: () => undefined,
          removeListener: () => undefined,
          addEventListener: () => undefined,
          removeEventListener: () => undefined,
          dispatchEvent: () => false,
        }) as MediaQueryList,
    )
  })
  afterEach(() => vi.restoreAllMocks())

  it("shows one day at a time instead of the weekly grid, starting on the first day with classes", () => {
    const { container } = render(
      <SectionScheduleCalendar items={sampleItems} />,
    )

    // No clipped 58rem grid.
    expect(
      container.querySelector('[style*="grid-template-columns"]'),
    ).toBeNull()

    const tabs = screen.getByRole("tablist", { name: "Day of the week" })
    // Every day is reachable and shows how many classes it holds.
    expect(
      within(tabs).getByRole("tab", { name: /^Mon\b.*0 classes/ }),
    ).toBeInTheDocument()
    const tuesday = within(tabs).getByRole("tab", { name: /^Tue\b.*1 class\b/ })
    expect(tuesday).toHaveAttribute("aria-selected", "true")

    // Tuesday's class is readable in full, not truncated to a sliver.
    const panel = screen.getByRole("tabpanel")
    expect(within(panel).getByText("IT101")).toBeInTheDocument()
    expect(
      within(panel).getByText("Introduction to Computing"),
    ).toBeInTheDocument()
    expect(within(panel).getByText("7:30 AM–9:30 AM")).toBeInTheDocument()
    expect(within(panel).getByText(/3F/)).toBeInTheDocument()
    expect(within(panel).getByText(/Danilo Portiles/)).toBeInTheDocument()
    expect(within(panel).getByText("HyFlex A")).toBeInTheDocument()
    // Wednesday's class is not shown until that day is chosen.
    expect(screen.queryByText("IT101L")).not.toBeInTheDocument()
  })

  it("switches day when a day tab is chosen", async () => {
    const user = userEvent.setup()
    render(<SectionScheduleCalendar items={sampleItems} />)

    await user.click(screen.getByRole("tab", { name: /^Wed\b/ }))

    const panel = screen.getByRole("tabpanel")
    expect(within(panel).getByText("IT101L")).toBeInTheDocument()
    expect(within(panel).getByText("7:30 AM–10:30 AM")).toBeInTheDocument()
    expect(
      screen.queryByText("Introduction to Computing"),
    ).not.toBeInTheDocument()
  })

  it("says so when the chosen day has no classes", async () => {
    const user = userEvent.setup()
    render(<SectionScheduleCalendar items={sampleItems} />)

    await user.click(screen.getByRole("tab", { name: /^Fri\b/ }))

    expect(screen.getByText("No classes on Friday.")).toBeInTheDocument()
  })

  it("lists the same class on every day it meets", async () => {
    const user = userEvent.setup()
    const mwf: SectionScheduleItem = {
      ...sampleItems[0],
      id: 201,
      subject_code: "MATH1",
      subject_title: "College Algebra",
      schedule_days: "MWF",
    }
    render(<SectionScheduleCalendar items={[mwf]} />)

    expect(
      screen.getByRole("tab", { name: /^Mon\b.*1 class\b/ }),
    ).toHaveAttribute("aria-selected", "true")
    await user.click(screen.getByRole("tab", { name: /^Fri\b/ }))
    expect(
      within(screen.getByRole("tabpanel")).getByText("MATH1"),
    ).toBeInTheDocument()
  })

  it("flags overlapping classes on the same day", () => {
    const overlapping: SectionScheduleItem[] = [
      {
        ...sampleItems[1],
        id: 301,
        subject_code: "CLASH1",
        schedule_days: "TUE",
        modality: "f2f",
      },
      {
        ...sampleItems[1],
        id: 302,
        subject_code: "CLASH2",
        schedule_days: "TUE",
        modality: "f2f",
        starts_at_time: "08:00:00",
      },
    ]
    render(<SectionScheduleCalendar items={overlapping} />)

    const panel = screen.getByRole("tabpanel")
    expect(within(panel).getAllByText("Conflict")).toHaveLength(2)
  })

  it("calls onSelectSubject when a class card is tapped", async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()
    render(
      <SectionScheduleCalendar
        items={sampleItems}
        onSelectSubject={onSelect}
      />,
    )

    await user.click(
      within(screen.getByRole("tabpanel")).getByRole("button", {
        name: /IT101.*click to edit assignment/i,
      }),
    )

    expect(onSelect).toHaveBeenCalledWith(
      expect.objectContaining({ id: 101, subject_code: "IT101" }),
    )
  })

  it("keeps the asynchronous / unscheduled tray under the agenda", () => {
    render(<SectionScheduleCalendar items={sampleItems} />)

    expect(
      screen.getByText(/Asynchronous & Unscheduled Subjects/i),
    ).toBeInTheDocument()
    expect(screen.getByText("ITASYNC")).toBeInTheDocument()
  })

  it("has no detectable accessibility violations", async () => {
    const { container } = render(
      <SectionScheduleCalendar items={sampleItems} />,
    )

    expect(await axe(container)).toHaveNoViolations()
  })
})

describe("SectionScheduleCalendarDialog", () => {
  it("renders modal with calendar view by default and allows switching to table", async () => {
    const user = userEvent.setup()
    const onOpenChange = vi.fn()

    render(
      <SectionScheduleCalendarDialog
        open={true}
        onOpenChange={onOpenChange}
        title="IT101 Schedule"
        subtitle="1st Year Block Section · 3 subjects"
        items={sampleItems}
      />,
    )

    const dialog = screen.getByRole("dialog", { name: /IT101 Schedule/i })
    expect(dialog).toBeInTheDocument()
    expect(within(dialog).getAllByText(/3 subjects/i).length).toBeGreaterThan(0)

    // Calendar is default
    expect(within(dialog).getByText("IT101")).toBeInTheDocument()
    expect(within(dialog).getByText("7:30 AM–9:30 AM")).toBeInTheDocument()

    // Switch to Table view
    await user.click(within(dialog).getByRole("radio", { name: "Table view" }))
    expect(within(dialog).getByRole("table")).toBeInTheDocument()
    expect(
      within(dialog).getByText("Introduction to Computing"),
    ).toBeInTheDocument()
  })
})
