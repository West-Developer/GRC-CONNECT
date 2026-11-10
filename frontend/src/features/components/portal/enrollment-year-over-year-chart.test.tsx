import { render, screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { EnrollmentYearOverYearChart } from "@/features/components/portal/enrollment-year-over-year-chart"

// recharts' ResponsiveContainer measures its container via
// getBoundingClientRect on mount before its ResizeObserver ever fires; jsdom
// reports every element as 0x0, so without this stub the chart's calculated
// width/height stay at 0 and recharts renders no series at all. Only the
// responsive-container div itself is stubbed to a fixed size — a blanket
// stub on every element also reports a nonzero size for the legend's own
// measurement, which makes recharts believe the legend needs the chart's
// entire height and collapses the plot area to zero.
//
// Captured only to be re-invoked via `.call(this)` below with an explicit
// receiver; never used as a detached reference.
// eslint-disable-next-line @typescript-eslint/unbound-method
const realGetBoundingClientRect = HTMLElement.prototype.getBoundingClientRect

beforeEach(() => {
  vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(
    function (this: HTMLElement): DOMRect {
      if (this.classList.contains("recharts-responsive-container")) {
        return {
          width: 600,
          height: 320,
          top: 0,
          left: 0,
          bottom: 320,
          right: 600,
          x: 0,
          y: 0,
          toJSON: () => "",
        }
      }
      return realGetBoundingClientRect.call(this)
    },
  )
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe("EnrollmentYearOverYearChart", () => {
  it("renders no chart and a fallback message for an empty points array", () => {
    const { container } = render(<EnrollmentYearOverYearChart points={[]} />)

    expect(
      screen.getByText("No official enrollment trend data is available yet."),
    ).toBeInTheDocument()
    expect(container.querySelector(".recharts-wrapper")).not.toBeInTheDocument()
  })

  it("keeps multiple school years in one chronological line", () => {
    const { container } = render(
      <EnrollmentYearOverYearChart
        points={[
          { school_year: "2023-2024", semester: "1st", enrollee_count: 100 },
          { school_year: "2023-2024", semester: "2nd", enrollee_count: 110 },
          { school_year: "2024-2025", semester: "1st", enrollee_count: 120 },
          { school_year: "2024-2025", semester: "2nd", enrollee_count: 130 },
        ]}
      />,
    )

    expect(container.querySelectorAll(".recharts-line")).toHaveLength(1)
    expect(container.querySelector(".recharts-legend-wrapper")).not.toBeInTheDocument()
  })

  it("renders one chronological official-enrollment trend across school years", () => {
    const { container } = render(
      <EnrollmentYearOverYearChart
        points={[
          { school_year: "2024-2025", semester: "1st", enrollee_count: 100 },
          { school_year: "2024-2025", semester: "2nd", enrollee_count: 108 },
          { school_year: "2025-2026", semester: "1st", enrollee_count: 92 },
        ]}
      />,
    )

    expect(screen.getByText("Official Enrollment Trend")).toBeInTheDocument()
    expect(container.querySelectorAll(".recharts-line")).toHaveLength(1)
    expect(container.querySelector(".recharts-legend-wrapper")).not.toBeInTheDocument()
  })

  it("generalizes across many school years without creating duplicate series", () => {
    const { container } = render(
      <EnrollmentYearOverYearChart
        points={[
          { school_year: "2022-2023", semester: "1st", enrollee_count: 80 },
          { school_year: "2023-2024", semester: "1st", enrollee_count: 100 },
          { school_year: "2024-2025", semester: "1st", enrollee_count: 120 },
          { school_year: "2025-2026", semester: "1st", enrollee_count: 140 },
        ]}
      />,
    )

    expect(container.querySelectorAll(".recharts-line")).toHaveLength(1)
  })

  it("renders a single line and no legend box for a single school year", () => {
    const { container } = render(
      <EnrollmentYearOverYearChart
        points={[
          { school_year: "2024-2025", semester: "1st", enrollee_count: 120 },
          { school_year: "2024-2025", semester: "2nd", enrollee_count: 130 },
        ]}
      />,
    )

    expect(container.querySelectorAll(".recharts-line")).toHaveLength(1)
    expect(
      container.querySelector(".recharts-legend-wrapper"),
    ).not.toBeInTheDocument()
  })
})
