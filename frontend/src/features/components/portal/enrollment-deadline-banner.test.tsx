import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { EnrollmentDeadlineBanner } from "@/features/components/portal/enrollment-deadline-banner"

describe("EnrollmentDeadlineBanner", () => {
  it("tells the student the account will be deactivated, why, and what unlocks the rest", () => {
    render(
      <EnrollmentDeadlineBanner
        deadlineAt="2026-10-21T10:00:00Z"
        now={new Date("2026-10-14T10:00:00Z")}
      />,
    )

    const banner = screen.getByRole("status")

    expect(banner).toHaveTextContent(
      "This account will be deactivated in 7 days (October 21, 2026)",
    )
    expect(banner).toHaveTextContent(
      "unless your enrollment is completed and paid",
    )
    expect(banner).toHaveTextContent(
      "The other options unlock once you are enrolled and have paid.",
    )
  })

  it("says today once the deadline has passed but the hourly run has not closed the account yet", () => {
    render(
      <EnrollmentDeadlineBanner
        deadlineAt="2026-10-14T09:00:00Z"
        now={new Date("2026-10-14T10:00:00Z")}
      />,
    )

    expect(screen.getByRole("status")).toHaveTextContent(
      "This account will be deactivated today",
    )
  })
})
