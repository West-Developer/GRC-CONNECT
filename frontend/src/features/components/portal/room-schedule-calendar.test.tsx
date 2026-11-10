import { act, render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { RoomScheduleCalendar } from "@/features/components/portal/room-schedule-calendar"

describe("RoomScheduleCalendar", () => {
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

  function renderCalendar(onSelectSlot = vi.fn()) {
    render(
      <RoomScheduleCalendar week={new Map()} onSelectSlot={onSelectSlot} />,
    )

    return onSelectSlot
  }

  function slotsOf(day: string) {
    return screen.queryAllByRole("button", {
      name: new RegExp(`^${day} .* available`),
    })
  }

  it("shows the whole week when there is room for it", () => {
    renderCalendar()
    boxWidth = 1100
    act(() => notify())

    for (const day of ["Monday", "Wednesday", "Saturday"]) {
      expect(slotsOf(day).length).toBeGreaterThan(0)
    }
    expect(
      screen.queryByRole("group", { name: "Day of the week" }),
    ).not.toBeInTheDocument()
  })

  it("shows one day at a time, chosen with the day buttons, when the box is narrower than the week", async () => {
    const user = userEvent.setup()
    renderCalendar()
    boxWidth = 500
    act(() => notify())

    expect(
      screen.getByRole("group", { name: "Day of the week" }),
    ).toBeInTheDocument()
    expect(slotsOf("Monday").length).toBeGreaterThan(0)
    expect(slotsOf("Wednesday")).toHaveLength(0)
    expect(screen.getByRole("button", { name: "Mon" })).toHaveAttribute(
      "aria-pressed",
      "true",
    )

    await user.click(screen.getByRole("button", { name: "Wed" }))

    expect(slotsOf("Wednesday").length).toBeGreaterThan(0)
    expect(slotsOf("Monday")).toHaveLength(0)
    expect(screen.getByRole("button", { name: "Wed" })).toHaveAttribute(
      "aria-pressed",
      "true",
    )
  })

  it("still lets a free slot be picked in the one-day view, for the day that is showing", async () => {
    const user = userEvent.setup()
    const onSelectSlot = renderCalendar()
    boxWidth = 500
    act(() => notify())

    await user.click(screen.getByRole("button", { name: "Wed" }))
    await user.click(slotsOf("Wednesday")[0])

    expect(onSelectSlot).toHaveBeenCalledWith(3, 0)
  })

  it("goes back to the whole week when the box grows, such as the sidebar closing", () => {
    renderCalendar()
    boxWidth = 500
    act(() => notify())
    expect(slotsOf("Saturday")).toHaveLength(0)

    boxWidth = 1100
    act(() => notify())

    expect(slotsOf("Saturday").length).toBeGreaterThan(0)
  })
})
