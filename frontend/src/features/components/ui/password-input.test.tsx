import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"

import { PasswordInput } from "@/features/components/ui/password-input"

describe("PasswordInput", () => {
  it("renders masked by default and toggles to visible text on click", async () => {
    const user = userEvent.setup()
    render(<PasswordInput id="pw" aria-label="Password" />)

    const input = screen.getByLabelText("Password")
    expect(input).toHaveAttribute("type", "password")

    await user.click(screen.getByRole("button", { name: "Show password" }))
    expect(input).toHaveAttribute("type", "text")

    await user.click(screen.getByRole("button", { name: "Hide password" }))
    expect(input).toHaveAttribute("type", "password")
  })

  it("forwards native input props to the underlying input", () => {
    render(
      <PasswordInput
        id="pw"
        aria-label="Password"
        disabled
        aria-invalid
        autoComplete="new-password"
      />,
    )

    const input = screen.getByLabelText("Password")
    expect(input).toBeDisabled()
    expect(input).toHaveAttribute("aria-invalid", "true")
    expect(input).toHaveAttribute("autocomplete", "new-password")
  })

  it("forwards onChange so it works with react-hook-form's register() spread", async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<PasswordInput id="pw" aria-label="Password" onChange={onChange} />)

    await user.type(screen.getByLabelText("Password"), "a")

    expect(onChange).toHaveBeenCalled()
  })
})
