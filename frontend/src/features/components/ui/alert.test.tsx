import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { Alert, AlertDescription } from "@/features/components/ui/alert"

describe("Alert", () => {
  it("announces the default variant politely, not assertively", () => {
    render(<Alert>Enrollment submitted.</Alert>)

    const alert = screen.getByRole("status")
    expect(alert).toHaveAttribute("aria-live", "polite")
  })

  it("announces the destructive variant assertively", () => {
    render(<Alert variant="destructive">Something failed.</Alert>)

    const alert = screen.getByRole("alert")
    expect(alert).not.toHaveAttribute("aria-live")
  })

  it("still renders its description content for both variants", () => {
    render(
      <Alert>
        <AlertDescription>Queue ticket: A-042.</AlertDescription>
      </Alert>,
    )

    expect(screen.getByText("Queue ticket: A-042.")).toBeInTheDocument()
  })
})
