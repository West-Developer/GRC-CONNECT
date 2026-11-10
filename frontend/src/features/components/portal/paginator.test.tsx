import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"

import { Paginator } from "@/features/components/portal/paginator"

describe("Paginator", () => {
  it("shows the current page and total", () => {
    render(
      <Paginator currentPage={2} lastPage={5} onPageChange={() => undefined} />,
    )

    expect(screen.getByText("Page 2 of 5")).toBeInTheDocument()
  })

  it("disables Previous on the first page and Next on the last page", () => {
    render(
      <Paginator currentPage={1} lastPage={1} onPageChange={() => undefined} />,
    )

    expect(screen.getByRole("button", { name: "Previous page" })).toBeDisabled()
    expect(screen.getByRole("button", { name: "Next page" })).toBeDisabled()
  })

  it("calls onPageChange with the adjacent page", async () => {
    const onPageChange = vi.fn()
    const user = userEvent.setup()
    render(
      <Paginator currentPage={2} lastPage={5} onPageChange={onPageChange} />,
    )

    await user.click(screen.getByRole("button", { name: "Next page" }))
    expect(onPageChange).toHaveBeenCalledWith(3)

    await user.click(screen.getByRole("button", { name: "Previous page" }))
    expect(onPageChange).toHaveBeenCalledWith(1)
  })

  it("disables both buttons when disabled is set, regardless of page", () => {
    render(
      <Paginator
        currentPage={2}
        lastPage={5}
        onPageChange={() => undefined}
        disabled
      />,
    )

    expect(screen.getByRole("button", { name: "Previous page" })).toBeDisabled()
    expect(screen.getByRole("button", { name: "Next page" })).toBeDisabled()
  })
})
