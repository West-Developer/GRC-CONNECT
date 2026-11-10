import { render } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { Skeleton } from "@/features/components/ui/skeleton"

describe("Skeleton", () => {
  it("is hidden from assistive technology as a decorative placeholder", () => {
    const { container } = render(<Skeleton data-testid="placeholder" />)

    expect(
      container.querySelector('[data-testid="placeholder"]'),
    ).toHaveAttribute("aria-hidden", "true")
  })
})
