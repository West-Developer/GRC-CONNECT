import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"

describe("CardTitle", () => {
  it("renders a real h2 heading by default", () => {
    render(<CardTitle>Payment queue</CardTitle>)

    expect(
      screen.getByRole("heading", { level: 2, name: "Payment queue" }),
    ).toBeInTheDocument()
  })

  it("renders the requested heading level", () => {
    render(<CardTitle level={3}>Grade encoding</CardTitle>)

    expect(
      screen.getByRole("heading", { level: 3, name: "Grade encoding" }),
    ).toBeInTheDocument()
  })

  it("is reachable via heading navigation inside a Card, with no axe violations", async () => {
    const { container } = render(
      <Card>
        <CardHeader>
          <CardTitle level={3}>Pending payment confirmations</CardTitle>
        </CardHeader>
        <CardContent>Body text</CardContent>
      </Card>,
    )

    const { axe } = await import("vitest-axe")
    expect(await axe(container)).toHaveNoViolations()
    expect(
      screen.getByRole("heading", {
        level: 3,
        name: "Pending payment confirmations",
      }),
    ).toBeInTheDocument()
  })
})
