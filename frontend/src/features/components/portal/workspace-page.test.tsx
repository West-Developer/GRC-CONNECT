import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { WorkspacePage } from "@/features/components/portal/workspace-page"

describe("WorkspacePage", () => {
  it("renders a labelled section with a real h1 heading", () => {
    render(
      <WorkspacePage title="Class rosters" description="Review your sections.">
        <p>Body content</p>
      </WorkspacePage>,
    )

    const heading = screen.getByRole("heading", {
      level: 1,
      name: "Class rosters",
    })
    expect(heading).toBeInTheDocument()
    expect(screen.getByText("Review your sections.")).toBeInTheDocument()
    expect(screen.getByText("Body content")).toBeInTheDocument()

    const region = screen.getByRole("region", { name: "Class rosters" })
    expect(region).toHaveAttribute("aria-labelledby", heading.id)
  })

  it("renders the unauthorized state with a real heading instead of a bare paragraph", () => {
    render(
      <WorkspacePage title="Class rosters" unauthorized>
        <p>Should not render</p>
      </WorkspacePage>,
    )

    expect(
      screen.getByRole("heading", { level: 1, name: "Class rosters" }),
    ).toBeInTheDocument()
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(screen.queryByText("Should not render")).not.toBeInTheDocument()
  })

  it("renders header actions when provided", () => {
    render(
      <WorkspacePage title="Audit logs" actions={<button>Export</button>}>
        <p>Body</p>
      </WorkspacePage>,
    )

    expect(screen.getByRole("button", { name: "Export" })).toBeInTheDocument()
  })

  it("shows a last-updated indicator once a successful fetch timestamp exists", () => {
    render(
      <WorkspacePage title="Payment queue" lastUpdated={Date.now() - 2000}>
        <p>Body</p>
      </WorkspacePage>,
    )

    expect(screen.getByText(/Updated just now/)).toBeInTheDocument()
  })

  it("shows no last-updated indicator before any fetch has completed", () => {
    render(
      <WorkspacePage title="Payment queue" lastUpdated={0}>
        <p>Body</p>
      </WorkspacePage>,
    )

    expect(screen.queryByText(/Updated/)).not.toBeInTheDocument()
  })
})
