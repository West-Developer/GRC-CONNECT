import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { Textarea } from "@/features/components/ui/textarea"

describe("Textarea", () => {
  it("renders a textarea element that accepts an accessible label", () => {
    render(
      <>
        <label htmlFor="reason">Reason</label>
        <Textarea id="reason" />
      </>,
    )

    expect(screen.getByLabelText("Reason")).toBeInstanceOf(HTMLTextAreaElement)
  })

  it("respects disabled state", () => {
    render(<Textarea aria-label="Notes" disabled />)

    expect(screen.getByLabelText("Notes")).toBeDisabled()
  })
})
