import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import {
  Field,
  FieldError,
  FieldLabel,
  useFieldError,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"

function EmailField({ inputId, error }: { inputId: string; error?: string }) {
  const { errorId, inputProps } = useFieldError(!!error)

  return (
    <Field data-invalid={!!error}>
      <FieldLabel htmlFor={inputId}>Email</FieldLabel>
      <Input id={inputId} {...inputProps} />
      {error && <FieldError id={errorId}>{error}</FieldError>}
    </Field>
  )
}

describe("useFieldError", () => {
  it("leaves the input valid and undescribed when there is no error", () => {
    render(<EmailField inputId="email-1" />)

    const input = screen.getByLabelText("Email")
    expect(input).toHaveAttribute("aria-invalid", "false")
    expect(input).not.toHaveAttribute("aria-describedby")
  })

  it("marks the input invalid and associates it with the error text", () => {
    render(
      <EmailField
        inputId="email-2"
        error="The email has already been taken."
      />,
    )

    const input = screen.getByLabelText("Email")
    const error = screen.getByText("The email has already been taken.")

    expect(input).toHaveAttribute("aria-invalid", "true")
    expect(input).toHaveAttribute("aria-describedby", error.id)
  })

  it("gives each field instance a distinct id", () => {
    render(
      <>
        <EmailField inputId="email-3" error="First error." />
        <EmailField inputId="email-4" error="Second error." />
      </>,
    )

    const errors = screen.getAllByText(/error\./)
    expect(errors[0].id).not.toBe(errors[1].id)
  })
})
