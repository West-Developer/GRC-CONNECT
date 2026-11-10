import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import type { AuthSession } from "@/features/auth/auth-types"
import { PrivacyAgreementGate } from "@/features/components/privacy/privacy-agreement-gate"
import { agreedPrivacyNotice } from "@/tests/privacy-notice-fixture"
import { renderWithSession, testSession } from "@/tests/render-app"

const infoPayload = {
  data: {
    type: "privacy-notice",
    version: "2026-10-18",
    effective_date: "2026-10-18",
    controller: "Global Reciprocal Colleges",
    contact: { name: null, email: "info@grc.edu.ph", phone: null },
  },
}

const agreedUser = {
  type: "user",
  id: 1,
  name: "Test Person",
  email: "student@grc.test",
  role: "student",
  role_label: "Student",
  college: null,
  status: "active",
  privacy_notice: agreedPrivacyNotice,
}

function urlOf(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

/** What the session holds for a person who has not agreed yet (the camel-case form of the API payload). */
const pendingSessionState: AuthSession["privacyNotice"] = {
  version: "2026-10-18",
  acceptedVersion: null,
  requiresAcceptance: true,
}

function sessionWith(
  privacyNotice: AuthSession["privacyNotice"],
  role: AuthSession["role"] = "student",
): AuthSession {
  return { ...testSession, role, privacyNotice }
}

describe("PrivacyAgreementGate", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input, init) => {
      const url = urlOf(input)
      if (url.endsWith("/api/v1/privacy-notice")) {
        return Promise.resolve(new Response(JSON.stringify(infoPayload)))
      }
      if (
        url.endsWith("/api/v1/auth/privacy-notice/accept") &&
        init?.method === "POST"
      ) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: agreedUser })),
        )
      }
      return Promise.reject(new Error(`Unexpected request: ${url}`))
    })
  })

  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  it("shows what is collected, why and who sees it before anything else", async () => {
    renderWithSession(<PrivacyAgreementGate />, {
      session: sessionWith(pendingSessionState),
    })

    expect(
      screen.getByRole("heading", {
        name: "Before you continue, please read how we use your data",
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("region", { name: "Data Privacy Notice" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: "Who this notice is for" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("heading", {
        name: "What we keep, why, and who sees it",
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: "Identity and contact details" }),
    ).toBeInTheDocument()
    // The contact comes from the API.
    expect(
      await screen.findByRole("link", { name: "info@grc.edu.ph" }),
    ).toBeInTheDocument()
  })

  it("cannot be agreed to until the box is ticked, then records the version that was read", async () => {
    const user = userEvent.setup()
    const replaceSession = vi.fn()
    renderWithSession(<PrivacyAgreementGate />, {
      session: sessionWith(pendingSessionState),
      replaceSession,
    })
    const agree = screen.getByRole("button", { name: "I agree and continue" })

    expect(agree).toBeDisabled()
    await user.click(agree)
    expect(fetchMock).not.toHaveBeenCalledWith(
      expect.stringContaining("/privacy-notice/accept"),
      expect.anything(),
    )

    await user.click(
      screen.getByRole("checkbox", {
        name: /I have read and understood the Data Privacy Notice/,
      }),
    )
    expect(agree).toBeEnabled()
    await user.click(agree)

    await waitFor(() => expect(replaceSession).toHaveBeenCalledWith(agreedUser))
    const accept = fetchMock.mock.calls.find(([input]) =>
      urlOf(input).endsWith("/api/v1/auth/privacy-notice/accept"),
    )
    expect(accept?.[1]?.method).toBe("POST")
    expect(typeof accept?.[1]?.body).toBe("string")
    expect(JSON.parse(accept?.[1]?.body as string)).toEqual({
      notice_version: "2026-10-18",
    })
  })

  it("lets a person who does not agree sign out instead", async () => {
    const user = userEvent.setup()
    const signOut = vi.fn()
    renderWithSession(<PrivacyAgreementGate />, {
      session: sessionWith(pendingSessionState),
      signOut,
    })

    await user.click(
      screen.getByRole("button", { name: "I do not agree — sign out" }),
    )

    expect(signOut).toHaveBeenCalledOnce()
  })

  it("asks a staff member to keep the data they handle confidential", () => {
    renderWithSession(<PrivacyAgreementGate />, {
      session: sessionWith(pendingSessionState, "admission_staff"),
    })

    expect(screen.getByTestId("privacy-agreement-text")).toHaveTextContent(
      /keep the personal data of students and colleagues that I handle confidential/,
    )
  })

  it("says the notice has changed when the person had agreed to an older one", () => {
    renderWithSession(<PrivacyAgreementGate />, {
      session: sessionWith({
        version: "2026-10-18",
        acceptedVersion: "2025-01-01",
        requiresAcceptance: true,
      }),
    })

    expect(
      screen.getByRole("heading", {
        name: "Our Data Privacy Notice has changed",
      }),
    ).toBeInTheDocument()
  })

  it("will not record an agreement to wording the person did not read", () => {
    // The server is already on a newer version than this page was built with.
    renderWithSession(<PrivacyAgreementGate />, {
      session: sessionWith({
        version: "2099-01-01",
        acceptedVersion: null,
        requiresAcceptance: true,
      }),
    })

    expect(screen.getByRole("alert")).toHaveTextContent(
      /A newer version of the Data Privacy Notice/,
    )
    expect(screen.getByRole("checkbox")).toBeDisabled()
    expect(
      screen.getByRole("button", { name: "I agree and continue" }),
    ).toBeDisabled()
  })

  it("shows why the agreement was not saved", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) => {
      const url = urlOf(input)
      if (url.endsWith("/api/v1/privacy-notice")) {
        return Promise.resolve(new Response(JSON.stringify(infoPayload)))
      }
      return Promise.resolve(
        new Response(
          JSON.stringify({
            error: {
              code: "VALIDATION_FAILED",
              message:
                "The Data Privacy Notice was updated. Reload the page and read the current version.",
              errors: {},
              request_id: "r-1",
            },
          }),
          { status: 422 },
        ),
      )
    })
    renderWithSession(<PrivacyAgreementGate />, {
      session: sessionWith(pendingSessionState),
    })

    await user.click(screen.getByRole("checkbox"))
    await user.click(
      screen.getByRole("button", { name: "I agree and continue" }),
    )

    expect(
      await screen.findByText("Your agreement was not saved"),
    ).toBeInTheDocument()
  })

  it("has no accessibility violations", async () => {
    const { container } = renderWithSession(<PrivacyAgreementGate />, {
      session: sessionWith(pendingSessionState),
    })

    expect(
      await screen.findByRole("link", { name: "info@grc.edu.ph" }),
    ).toBeInTheDocument()
    expect(await axe(container)).toHaveNoViolations()
  })
})
