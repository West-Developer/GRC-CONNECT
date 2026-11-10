import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { Breadcrumb } from "@/features/components/common/breadcrumb"

describe("Breadcrumb", () => {
  it("renders a nav landmark with the standard accessible name", () => {
    render(<Breadcrumb items={[{ label: "Portal overview" }]} />)

    expect(
      screen.getByRole("navigation", { name: "Breadcrumb" }),
    ).toBeInTheDocument()
  })

  it("links every item except the last", () => {
    render(
      <Breadcrumb
        items={[
          { label: "Portal overview", href: "/portal" },
          { label: "Class rosters" },
        ]}
      />,
    )

    expect(
      screen.getByRole("link", { name: "Portal overview" }),
    ).toHaveAttribute("href", "/portal")
    expect(
      screen.queryByRole("link", { name: "Class rosters" }),
    ).not.toBeInTheDocument()
  })

  it("marks the last item as the current page", () => {
    render(
      <Breadcrumb
        items={[
          { label: "Portal overview", href: "/portal" },
          { label: "Class rosters" },
        ]}
      />,
    )

    expect(screen.getByText("Class rosters")).toHaveAttribute(
      "aria-current",
      "page",
    )
  })

  it("renders a single current-page item with no separator", () => {
    render(<Breadcrumb items={[{ label: "Portal overview" }]} />)

    const list = screen.getByRole("list")
    expect(list.children).toHaveLength(1)
    expect(screen.getByText("Portal overview")).toHaveAttribute(
      "aria-current",
      "page",
    )
  })
})
