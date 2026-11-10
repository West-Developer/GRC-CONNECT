import { act, render, screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { useElementWidth } from "@/features/hooks/use-element-width"

function Probe() {
  const [ref, width] = useElementWidth<HTMLDivElement>()

  return (
    <div ref={ref} data-testid="box">
      {width}
    </div>
  )
}

describe("useElementWidth", () => {
  let notify: () => void = () => undefined
  let boxWidth = 0

  beforeEach(() => {
    boxWidth = 0
    vi.stubGlobal(
      "ResizeObserver",
      class {
        constructor(callback: () => void) {
          notify = callback
        }
        observe = vi.fn()
        disconnect = vi.fn()
      },
    )
    vi.spyOn(HTMLElement.prototype, "clientWidth", "get").mockImplementation(
      () => boxWidth,
    )
  })

  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it("is 0 until the box has been measured, as it is wherever there is no layout", () => {
    render(<Probe />)

    expect(screen.getByTestId("box")).toHaveTextContent("0")
  })

  it("follows the box as it is resized", () => {
    render(<Probe />)

    boxWidth = 640
    act(() => notify())
    expect(screen.getByTestId("box")).toHaveTextContent("640")

    boxWidth = 1100
    act(() => notify())
    expect(screen.getByTestId("box")).toHaveTextContent("1100")
  })
})
