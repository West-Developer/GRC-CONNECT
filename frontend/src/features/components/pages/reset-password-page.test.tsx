import { screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"

import { ResetPasswordPage } from "@/features/components/pages/reset-password-page"
import { routerMock } from "@/tests/navigation-mock"
import { renderWithAuthProvider } from "@/tests/render-app"

function urlOf(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

async function completeForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText("Email address"), "student@grc.test")
  await user.type(screen.getByLabelText("One-time reset code"), "123456")
  await user.type(screen.getByLabelText("New password"), "New-Secure-Password1!")
  await user.type(
    screen.getByLabelText("Confirm new password"),
    "New-Secure-Password1!",
  )
}

describe("ResetPasswordPage", () => {
  afterEach(() => vi.unstubAllGlobals())

  it("submits the reset code and new password, then redirects to login", async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(
      new Response(
        JSON.stringify({ data: { type: "reset-password", status: "reset" } }),
      ),
    )
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<ResetPasswordPage />, { route: "/reset-password" })

    await completeForm(user)
    await user.click(screen.getByRole("button", { name: "Reset password" }))

    expect(
      await screen.findByRole("heading", {
        name: "Your password has changed.",
      }),
    ).toBeInTheDocument()
    const requestUrl = fetchMock.mock.calls[0]?.[0]
    expect(requestUrl ? urlOf(requestUrl) : "").toContain(
      "/api/v1/auth/reset-password",
    )
    const body: unknown = JSON.parse(
      fetchMock.mock.calls[0]?.[1]?.body as string,
    )
    expect(body).toEqual({
      email: "student@grc.test",
      code: "123456",
      password: "New-Secure-Password1!",
      password_confirmation: "New-Secure-Password1!",
    })

    await user.click(
      screen.getByRole("button", { name: "Continue to sign in" }),
    )
    expect(routerMock.replace).toHaveBeenCalledWith(
      "/login?passwordReset=complete",
    )
  })

  it("keeps the form visible for an invalid or expired code", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn<typeof fetch>().mockResolvedValue(
        new Response(
          JSON.stringify({
            error: {
              code: "VALIDATION_FAILED",
              message: "The given data was invalid.",
              errors: { code: ["This reset code is invalid or expired."] },
              request_id: "reset-test-request",
            },
          }),
          { status: 422 },
        ),
      ),
    )
    const user = userEvent.setup()
    renderWithAuthProvider(<ResetPasswordPage />, { route: "/reset-password" })

    await completeForm(user)
    await user.click(screen.getByRole("button", { name: "Reset password" }))

    expect(
      await screen.findByText("This reset code is invalid or expired."),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: "Reset your password" }),
    ).toBeInTheDocument()
  })

  it("pre-populates email and code from URL search parameters", () => {
    renderWithAuthProvider(<ResetPasswordPage />, {
      route: "/reset-password?email=student%40grc.test&code=123456",
    })

    expect(screen.getByLabelText("Email address")).toHaveValue(
      "student@grc.test",
    )
    expect(screen.getByLabelText("One-time reset code")).toHaveValue("123456")
  })

  it("asks for a numeric 6-digit code and rejects anything else before calling the API", async () => {
    const fetchMock = vi.fn<typeof fetch>()
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<ResetPasswordPage />, { route: "/reset-password" })

    await user.type(screen.getByLabelText("Email address"), "student@grc.test")
    await user.type(screen.getByLabelText("One-time reset code"), "12345")
    await user.type(screen.getByLabelText("New password"), "New-Secure-Password1!")
    await user.type(
      screen.getByLabelText("Confirm new password"),
      "New-Secure-Password1!",
    )
    await user.click(screen.getByRole("button", { name: "Reset password" }))

    expect(
      await screen.findByText("Enter the 6-digit code from your email."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("rejects a weak password before ever calling the API", async () => {
    const fetchMock = vi.fn<typeof fetch>()
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<ResetPasswordPage />, { route: "/reset-password" })

    await user.type(screen.getByLabelText("Email address"), "student@grc.test")
    await user.type(screen.getByLabelText("One-time reset code"), "123456")
    await user.type(screen.getByLabelText("New password"), "alllowercase1!")
    await user.type(
      screen.getByLabelText("Confirm new password"),
      "alllowercase1!",
    )
    await user.click(screen.getByRole("button", { name: "Reset password" }))

    expect(
      await screen.findByText("Include an uppercase letter."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("rejects mismatched password confirmation before calling the API", async () => {
    const fetchMock = vi.fn<typeof fetch>()
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<ResetPasswordPage />, { route: "/reset-password" })

    await user.type(screen.getByLabelText("Email address"), "student@grc.test")
    await user.type(screen.getByLabelText("One-time reset code"), "123456")
    await user.type(screen.getByLabelText("New password"), "New-Secure-Password1!")
    await user.type(
      screen.getByLabelText("Confirm new password"),
      "Different-Password1!",
    )
    await user.click(screen.getByRole("button", { name: "Reset password" }))

    expect(await screen.findByText("Passwords must match.")).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
