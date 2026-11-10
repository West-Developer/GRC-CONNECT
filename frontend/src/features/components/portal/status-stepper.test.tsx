import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { StatusStepper } from "@/features/components/portal/status-stepper"

describe("StatusStepper", () => {
  it("renders each stage with its label", () => {
    render(
      <StatusStepper
        stages={[
          { label: "Submitted", done: true, current: false },
          { label: "Approved", done: false, current: true },
          { label: "Enrolled", done: false, current: false },
        ]}
      />,
    )

    expect(screen.getByText("Submitted")).toBeInTheDocument()
    expect(screen.getByText("Approved")).toBeInTheDocument()
    expect(screen.getByText("Enrolled")).toBeInTheDocument()
    // A completed stage renders a checkmark instead of its position number.
    expect(screen.queryByText("1")).not.toBeInTheDocument()
    expect(screen.getByText("2")).toBeInTheDocument()
  })

  it("renders the stopped message instead of the stepper when set", () => {
    render(
      <StatusStepper
        stages={[{ label: "Submitted", done: true, current: false }]}
        stoppedMessage="This enrollment is rejected and is not progressing further."
      />,
    )

    expect(
      screen.getByText(
        "This enrollment is rejected and is not progressing further.",
      ),
    ).toBeInTheDocument()
    expect(screen.queryByText("Submitted")).not.toBeInTheDocument()
  })
})
