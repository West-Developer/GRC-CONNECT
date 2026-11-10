import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { StoppedStudentsTrendChart } from "@/features/components/portal/stopped-students-trend-chart"
import type { AnalyticsYearOverYearPoint } from "@/features/schemas/dashboard-schema"

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

const samplePoints: AnalyticsYearOverYearPoint[] = [
  {
    school_year: "2024-2025",
    semester: "2nd",
    enrollee_count: 140,
    stopped_count: 15,
    attrition_rate: 9.68,
  },
  {
    school_year: "2024-2025",
    semester: "1st",
    enrollee_count: 155,
    stopped_count: 0,
    attrition_rate: 0,
  },
  {
    school_year: "2025-2026",
    semester: "1st",
    enrollee_count: 160,
    stopped_count: 0,
    attrition_rate: 0,
  },
  {
    school_year: "2025-2026",
    semester: "2nd",
    enrollee_count: 135,
    stopped_count: 25,
    attrition_rate: 15.63,
  },
]

describe("StoppedStudentsTrendChart", () => {
  it("renders a fallback message when points array is empty", () => {
    const { container } = render(<StoppedStudentsTrendChart points={[]} />)

    expect(
      screen.getByText("No stopped student trend data is available yet."),
    ).toBeInTheDocument()
    expect(container.querySelector(".recharts-wrapper")).not.toBeInTheDocument()
  })

  it("renders the stopped student metrics and chart when data is provided", () => {
    const { container } = render(
      <StoppedStudentsTrendChart points={samplePoints} />,
    )

    expect(screen.getByText("Stopped Students Trend")).toBeInTheDocument()
    expect(screen.getByText(/Total Stopped:/)).toBeInTheDocument()
    expect(screen.getByText("40")).toBeInTheDocument() // 15 + 25 = 40
    expect(screen.getByText(/Peak Attrition/)).toBeInTheDocument()
    expect(container.querySelector(".recharts-wrapper")).toBeInTheDocument()
  })

  it("allows switching between view modes", async () => {
    const user = userEvent.setup()
    render(<StoppedStudentsTrendChart points={samplePoints} />)

    const rateTab = screen.getByRole("tab", { name: "Attrition Rate (%)" })
    await user.click(rateTab)
    expect(rateTab).toHaveAttribute("aria-selected", "true")

    const comparativeTab = screen.getByRole("tab", {
      name: "Comparative View",
    })
    await user.click(comparativeTab)
    expect(comparativeTab).toHaveAttribute("aria-selected", "true")
  })
})

