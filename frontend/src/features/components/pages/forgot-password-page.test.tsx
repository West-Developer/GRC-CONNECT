import { screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"

import { ForgotPasswordPage } from "@/features/components/pages/forgot-password-page"
import { renderWithAuthProvider } from "@/tests/render-app"

function urlOf(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

const GENERIC_SENT_MESSAGE =
  "If an account exists for this email, a password reset code has been sent."

describe("ForgotPasswordPage", () => {
  afterEach(() => vi.unstubAllGlobals())

  it("shows the identical generic message on submit, regardless of the account's existence", async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            type: "forgot-password",
            status: "sent",
            message: GENERIC_SENT_MESSAGE,
          },
        }),
      ),
    )
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<ForgotPasswordPage />, { route: "/forgot-password" })

    await user.type(
      screen.getByLabelText("Email address"),
      "student@grc.test",
    )
    await user.click(screen.getByRole("button", { name: "Send reset code" }))

    expect(await screen.findByText(GENERIC_SENT_MESSAGE)).toBeInTheDocument()
    const requestUrl = fetchMock.mock.calls[0]?.[0]
    expect(requestUrl ? urlOf(requestUrl) : "").toContain(
      "/api/v1/auth/forgot-password",
    )
    const body: unknown = JSON.parse(
      fetchMock.mock.calls[0]?.[1]?.body as string,
    )
    expect(body).toEqual({ email: "student@grc.test" })
  })

  it("rejects a malformed email before ever calling the API", async () => {
    const fetchMock = vi.fn<typeof fetch>()
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<ForgotPasswordPage />, { route: "/forgot-password" })

    await user.type(screen.getByLabelText("Email address"), "not-an-email")
    await user.click(screen.getByRole("button", { name: "Send reset code" }))

    expect(
      await screen.findByText("Enter a valid email address."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("offers a link back to sign in", () => {
    renderWithAuthProvider(<ForgotPasswordPage />, { route: "/forgot-password" })

    expect(
      screen.getByRole("link", { name: "Return to sign in" }),
    ).toHaveAttribute("href", "/login")
  })
})
