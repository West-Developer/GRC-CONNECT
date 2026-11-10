import { act, render, screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  SectionScheduleCalendar,
  type SectionScheduleItem,
} from "@/features/components/portal/section-schedule-calendar"

const items: SectionScheduleItem[] = [
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
    modality: "f2f",
  },
]

/** The week grid is 58rem wide (928px): a box narrower than that must not be given the grid. */
describe("SectionScheduleCalendar in a box that is too narrow for the week grid", () => {
  let notify: () => void = () => undefined
  let boxWidth = 0

  beforeEach(() => {
    boxWidth = 0
    vi.stubGlobal(
      "ResizeObserver",
      class {
        constructor(callback: () => void) {
          notify = callback
        }
        observe = vi.fn()
        disconnect = vi.fn()
      },
    )
    vi.spyOn(HTMLElement.prototype, "clientWidth", "get").mockImplementation(
      () => boxWidth,
    )
  })

  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it("keeps the week grid where there is room for it", () => {
    const { container } = render(<SectionScheduleCalendar items={items} />)
    boxWidth = 1100
    act(() => notify())

    expect(container.querySelector(".min-w-\\[58rem\\]")).not.toBeNull()
  })

  it("shows the day-by-day agenda instead of a grid that would scroll sideways", () => {
    const { container } = render(<SectionScheduleCalendar items={items} />)
    boxWidth = 700
    act(() => notify())

    expect(container.querySelector(".min-w-\\[58rem\\]")).toBeNull()
    // The class is still reachable, in the agenda.
    expect(screen.getAllByText("IT101").length).toBeGreaterThan(0)
  })

  it("goes back to the grid when the box grows", () => {
    const { container } = render(<SectionScheduleCalendar items={items} />)
    boxWidth = 700
    act(() => notify())
    expect(container.querySelector(".min-w-\\[58rem\\]")).toBeNull()

    boxWidth = 1000
    act(() => notify())

    expect(container.querySelector(".min-w-\\[58rem\\]")).not.toBeNull()
  })
})
