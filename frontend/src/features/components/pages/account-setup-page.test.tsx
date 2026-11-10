import { screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { toast } from "sonner"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { AccountSetupPage } from "@/features/components/pages/account-setup-page"
import { routerMock } from "@/tests/navigation-mock"
import { renderWithAuthProvider } from "@/tests/render-app"

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

function urlOf(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

/** The Data Privacy Notice has to be agreed to before an account is set up (ADR 0055). */
async function agreeToPrivacyNotice(user: ReturnType<typeof userEvent.setup>) {
  await user.click(
    screen.getByRole("checkbox", {
      name: /I have read and understood the Data Privacy Notice/,
    }),
  )
}

async function completeForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText("Email address"), "student@grc.test")
  await user.type(screen.getByLabelText("One-time setup code"), "123456")
  await user.type(
    screen.getByLabelText("New password"),
    "New-Secure-Password1!",
  )
  await user.type(
    screen.getByLabelText("Confirm new password"),
    "New-Secure-Password1!",
  )
}

describe("AccountSetupPage", () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.unstubAllGlobals())

  it("says what is asked and why, and cannot be submitted until the Data Privacy Notice is agreed to", async () => {
    const user = userEvent.setup()
    renderWithAuthProvider(<AccountSetupPage />, { route: "/account-setup" })

    expect(
      screen.getByRole("complementary", { name: "Data privacy" }),
    ).toHaveTextContent(/a password you choose.*Who can see it/)
    const submit = screen.getByRole("button", {
      name: "Create password and activate",
    })
    expect(submit).toBeDisabled()

    await agreeToPrivacyNotice(user)

    expect(submit).toBeEnabled()
  })

  it("tells a professor that their name, college and degree are kept to build schedules", () => {
    renderWithAuthProvider(<AccountSetupPage variant="faculty" />, {
      route: "/faculty-account-setup",
    })

    expect(
      screen.getByRole("complementary", { name: "Data privacy" }),
    ).toHaveTextContent(/your name, college or department, master’s degree/)
    expect(
      screen.getByRole("checkbox", {
        name: /keep the personal data of students/,
      }),
    ).toBeInTheDocument()
  })

  it("submits the separate code and redirects to login after successful activation", async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValue(
        new Response(
          JSON.stringify({ data: { type: "account-setup", status: "active" } }),
        ),
      )
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<AccountSetupPage />, { route: "/account-setup" })

    await completeForm(user)
    await agreeToPrivacyNotice(user)
    await user.click(
      screen.getByRole("button", { name: "Create password and activate" }),
    )

    expect(
      await screen.findByRole("heading", { name: "Your account is active." }),
    ).toBeInTheDocument()
    expect(toast.success).toHaveBeenCalledWith("Your account is now active.")
    const requestBody = fetchMock.mock.calls[0]?.[1]?.body
    const body: Record<string, unknown> =
      typeof requestBody === "string"
        ? (JSON.parse(requestBody) as Record<string, unknown>)
        : {}
    expect(body).toEqual({
      email: "student@grc.test",
      code: "123456",
      password: "New-Secure-Password1!",
      password_confirmation: "New-Secure-Password1!",
      // The Data Privacy Notice the person read and agreed to travels with the setup (ADR 0055).
      privacy_notice_acknowledged: true,
      privacy_notice_version: "2026-10-18",
    })
    const requestUrl = fetchMock.mock.calls[0]?.[0]
    expect(requestUrl ? urlOf(requestUrl) : "").not.toContain("123456")

    await user.click(
      screen.getByRole("button", { name: "Continue to sign in" }),
    )
    expect(routerMock.replace).toHaveBeenCalledWith(
      "/login?accountSetup=complete",
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
              errors: { code: ["The setup code is invalid or expired."] },
              request_id: "setup-test-request",
            },
          }),
          { status: 422 },
        ),
      ),
    )
    const user = userEvent.setup()
    renderWithAuthProvider(<AccountSetupPage />, { route: "/account-setup" })

    await completeForm(user)
    await agreeToPrivacyNotice(user)
    await user.click(
      screen.getByRole("button", { name: "Create password and activate" }),
    )

    expect(
      await screen.findByText("The setup code is invalid or expired."),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: "Set up your account" }),
    ).toBeInTheDocument()
    expect(toast.error).toHaveBeenCalledWith(
      "The setup code could not be verified.",
    )
  })

  it("lets the student reveal and re-hide both password fields independently", async () => {
    const user = userEvent.setup()
    renderWithAuthProvider(<AccountSetupPage />, { route: "/account-setup" })

    const password = screen.getByLabelText("New password")
    const confirm = screen.getByLabelText("Confirm new password")
    expect(password).toHaveAttribute("type", "password")
    expect(confirm).toHaveAttribute("type", "password")

    const [showPassword, showConfirm] = screen.getAllByRole("button", {
      name: "Show password",
    })
    await user.click(showPassword)
    expect(password).toHaveAttribute("type", "text")
    expect(confirm).toHaveAttribute("type", "password")

    await user.click(showConfirm)
    expect(confirm).toHaveAttribute("type", "text")

    await user.click(showPassword)
    expect(password).toHaveAttribute("type", "password")
    expect(confirm).toHaveAttribute("type", "text")
  })

  it("does not show a Full name field for the default (Student) variant", () => {
    renderWithAuthProvider(<AccountSetupPage />, { route: "/account-setup" })

    expect(screen.queryByLabelText("Full name")).not.toBeInTheDocument()
    expect(screen.getByText("Student account")).toBeInTheDocument()
  })

  it("pre-populates email and code from URL search parameters and shows 24-hour expiration", () => {
    renderWithAuthProvider(<AccountSetupPage />, {
      route: "/account-setup?email=student%40grc.test&code=123456",
    })

    expect(screen.getByLabelText("Email address")).toHaveValue(
      "student@grc.test",
    )
    expect(screen.getByLabelText("One-time setup code")).toHaveValue("123456")
    expect(
      screen.getByText(/The 6-digit code expires 24 hours after the latest/),
    ).toBeInTheDocument()
  })

  it("asks for a numeric 6-digit code and rejects anything else before calling the API", async () => {
    const user = userEvent.setup()
    renderWithAuthProvider(<AccountSetupPage />, { route: "/account-setup" })

    const codeInput = screen.getByLabelText("One-time setup code")
    expect(codeInput).toHaveAttribute("inputmode", "numeric")
    expect(codeInput).toHaveAttribute("maxlength", "6")

    await user.type(screen.getByLabelText("Email address"), "student@grc.test")
    await user.type(codeInput, "12345")
    await user.type(
      screen.getByLabelText("New password"),
      "New-Secure-Password1!",
    )
    await user.type(
      screen.getByLabelText("Confirm new password"),
      "New-Secure-Password1!",
    )
    await agreeToPrivacyNotice(user)
    await user.click(
      screen.getByRole("button", { name: "Create password and activate" }),
    )

    expect(
      await screen.findByText("Enter the 6-digit code from your email."),
    ).toBeInTheDocument()
  })

  it("rejects a weak password before ever calling the API", async () => {
    const fetchMock = vi.fn<typeof fetch>()
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<AccountSetupPage />, { route: "/account-setup" })

    await user.type(screen.getByLabelText("Email address"), "student@grc.test")
    await user.type(screen.getByLabelText("One-time setup code"), "123456")
    await user.type(screen.getByLabelText("New password"), "alllowercase1!")
    await user.type(
      screen.getByLabelText("Confirm new password"),
      "alllowercase1!",
    )
    await agreeToPrivacyNotice(user)
    await user.click(
      screen.getByRole("button", { name: "Create password and activate" }),
    )

    expect(
      await screen.findByText("Include an uppercase letter."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("allows requesting a new setup email if the code expired", async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            type: "resend-student-account-setup",
            status: "sent",
            message:
              "If a pending student account exists for this email, a new setup invitation has been sent.",
          },
        }),
      ),
    )
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<AccountSetupPage />, {
      route: "/account-setup?email=student%40grc.test",
    })

    const resendBtn = screen.getByRole("button", {
      name: "Resend setup email",
    })
    await user.click(resendBtn)

    expect(
      await screen.findByText(
        "If a pending student account exists for this email, a new setup invitation has been sent.",
      ),
    ).toBeInTheDocument()
    const requestUrl = fetchMock.mock.calls[0]?.[0]
    expect(requestUrl ? urlOf(requestUrl) : "").toContain(
      "/api/v1/auth/resend-student-account-setup",
    )
    const body: unknown = JSON.parse(
      fetchMock.mock.calls[0]?.[1]?.body as string,
    )
    expect(body).toEqual({ email: "student@grc.test" })
  })

  it("lets a professor supply their name and posts to the faculty setup endpoint", async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: { type: "faculty-account-setup", status: "active" },
        }),
      ),
    )
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<AccountSetupPage variant="faculty" />, {
      route: "/faculty-account-setup",
    })

    expect(screen.getByText("Faculty account")).toBeInTheDocument()

    await user.type(
      screen.getByLabelText("Email address"),
      "professor@grc.test",
    )
    await user.type(screen.getByLabelText("One-time setup code"), "123456")
    await user.type(screen.getByLabelText("Full name"), "Prof. Juan Dela Cruz")
    await user.type(
      screen.getByLabelText("New password"),
      "New-Secure-Password1!",
    )
    await user.type(
      screen.getByLabelText("Confirm new password"),
      "New-Secure-Password1!",
    )
    await agreeToPrivacyNotice(user)
    await user.click(
      screen.getByRole("button", { name: "Create password and activate" }),
    )

    expect(
      await screen.findByRole("heading", { name: "Your account is active." }),
    ).toBeInTheDocument()
    const requestUrl = fetchMock.mock.calls[0]?.[0]
    expect(requestUrl ? urlOf(requestUrl) : "").toContain(
      "/auth/faculty-account-setup",
    )
    const requestBody = fetchMock.mock.calls[0]?.[1]?.body
    const body: Record<string, unknown> =
      typeof requestBody === "string"
        ? (JSON.parse(requestBody) as Record<string, unknown>)
        : {}
    expect(body).toEqual({
      email: "professor@grc.test",
      code: "123456",
      name: "Prof. Juan Dela Cruz",
      password: "New-Secure-Password1!",
      password_confirmation: "New-Secure-Password1!",
      // The Data Privacy Notice the person read and agreed to travels with the setup (ADR 0055).
      privacy_notice_acknowledged: true,
      privacy_notice_version: "2026-10-18",
    })
  })

  it("lets an invited staff member supply their name and posts to the staff setup endpoint", async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: { type: "staff-account-setup", status: "active" },
        }),
      ),
    )
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithAuthProvider(<AccountSetupPage variant="staff" />, {
      route: "/staff-account-setup",
    })

    expect(screen.getByText("Staff account")).toBeInTheDocument()

    await user.type(screen.getByLabelText("Email address"), "dean@grc.test")
    await user.type(screen.getByLabelText("One-time setup code"), "123456")
    await user.type(screen.getByLabelText("Full name"), "Aurora Dean Santos")
    await user.type(
      screen.getByLabelText("New password"),
      "New-Secure-Password1!",
    )
    await user.type(
      screen.getByLabelText("Confirm new password"),
      "New-Secure-Password1!",
    )
    await agreeToPrivacyNotice(user)
    await user.click(
      screen.getByRole("button", { name: "Create password and activate" }),
    )

    expect(
      await screen.findByRole("heading", { name: "Your account is active." }),
    ).toBeInTheDocument()
    const requestUrl = fetchMock.mock.calls[0]?.[0]
    expect(requestUrl ? urlOf(requestUrl) : "").toContain(
      "/auth/staff-account-setup",
    )
    const requestBody = fetchMock.mock.calls[0]?.[1]?.body
    const body: Record<string, unknown> =
      typeof requestBody === "string"
        ? (JSON.parse(requestBody) as Record<string, unknown>)
        : {}
    expect(body).toEqual({
      email: "dean@grc.test",
      code: "123456",
      name: "Aurora Dean Santos",
      password: "New-Secure-Password1!",
      password_confirmation: "New-Secure-Password1!",
      // The Data Privacy Notice the person read and agreed to travels with the setup (ADR 0055).
      privacy_notice_acknowledged: true,
      privacy_notice_version: "2026-10-18",
    })
  })
})
