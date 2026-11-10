import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { EnrollmentAvailabilityBanner } from "@/features/components/portal/enrollment-availability-banner"
import type { AudienceAvailability } from "@/features/schemas/enrollment-window-schema"

function viewer(overrides: Partial<AudienceAvailability>): AudienceAvailability {
  return {
    audience: "year_1",
    label: "1st Year",
    opens_at: null,
    closes_at: null,
    is_open: true,
    reason: "open",
    ...overrides,
  }
}

describe("EnrollmentAvailabilityBanner", () => {
  it("renders nothing when there is no viewer", () => {
    const { container } = render(<EnrollmentAvailabilityBanner viewer={null} />)
    expect(container).toBeEmptyDOMElement()
  })

  it("shows an open banner naming the year level and close date", () => {
    render(
      <EnrollmentAvailabilityBanner
        viewer={viewer({ audience: "year_4", label: "4th Year", is_open: true, reason: "open", closes_at: "2028-07-15T00:00:00Z" })}
      />,
    )

    expect(screen.getByText("Enrollment is open")).toBeInTheDocument()
    expect(screen.getByText(/open for 4th Year/)).toBeInTheDocument()
  })

  it("shows a before-window banner with the open date", () => {
    render(
      <EnrollmentAvailabilityBanner
        viewer={viewer({
          audience: "year_2",
          label: "2nd Year",
          is_open: false,
          reason: "before_window",
          opens_at: "2028-08-01T00:00:00Z",
        })}
      />,
    )

    expect(screen.getByText("Enrollment has not opened yet")).toBeInTheDocument()
    expect(screen.getByText(/2nd Year/)).toBeInTheDocument()
  })

  it("shows an after-window banner", () => {
    render(
      <EnrollmentAvailabilityBanner
        viewer={viewer({ audience: "year_3", label: "3rd Year", is_open: false, reason: "after_window" })}
      />,
    )

    expect(screen.getByText("Enrollment window closed")).toBeInTheDocument()
  })

  it("shows a term-not-open banner", () => {
    render(
      <EnrollmentAvailabilityBanner
        viewer={viewer({ is_open: false, reason: "term_not_open" })}
      />,
    )

    expect(screen.getByText("Enrollment is not open")).toBeInTheDocument()
    expect(
      screen.getByText("The Registrar has not opened enrollment for this term yet."),
    ).toBeInTheDocument()
  })

  it("names the irregular audience instead of a year level", () => {
    render(
      <EnrollmentAvailabilityBanner
        viewer={viewer({
          audience: "irregular",
          label: "Irregular Students",
          is_open: false,
          reason: "before_window",
          opens_at: "2028-08-01T00:00:00Z",
        })}
      />,
    )

    expect(screen.getByText(/Irregular Students/)).toBeInTheDocument()
    expect(screen.queryByText(/Year/)).not.toBeInTheDocument()
  })

  it("shows a term-closed banner", () => {
    render(
      <EnrollmentAvailabilityBanner
        viewer={viewer({ is_open: false, reason: "term_closed" })}
      />,
    )

    expect(screen.getByText("This term has closed.")).toBeInTheDocument()
  })
})
