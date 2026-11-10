import { screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { PrivacyNoticePage } from "@/features/components/pages/privacy-notice-page"
import { renderWithSession } from "@/tests/render-app"

const infoPayload = {
  data: {
    type: "privacy-notice",
    version: "2026-10-18",
    effective_date: "2026-10-18",
    controller: "Global Reciprocal Colleges",
    contact: {
      name: "Jane Sample",
      email: "dpo@grc.test",
      phone: "02 8000 0000",
    },
  },
}

describe("PrivacyNoticePage", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockResolvedValue(new Response(JSON.stringify(infoPayload)))
  })

  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  it("is a public page that needs no sign-in and says who it is for and what is kept", () => {
    renderWithSession(<PrivacyNoticePage />, {
      session: null,
      status: "anonymous",
    })

    expect(
      screen.getByRole("heading", {
        level: 1,
        name: "How GRC uses your personal data",
      }),
    ).toBeInTheDocument()
    expect(
      screen.getAllByText(/Republic Act No. 10173/).length,
    ).toBeGreaterThan(0)
    expect(
      screen.getByRole("heading", { name: "Who this notice is for" }),
    ).toBeInTheDocument()
    expect(screen.getByText("Current students")).toBeInTheDocument()
    expect(screen.getByText("Professors and faculty")).toBeInTheDocument()
    expect(
      screen.getByRole("heading", {
        name: "What we keep, why, and who sees it",
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: "Your rights" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: "If you work with student records" }),
    ).toBeInTheDocument()
  })

  it("shows the version, the date it took effect and the officer to write to", async () => {
    renderWithSession(<PrivacyNoticePage />, {
      session: null,
      status: "anonymous",
    })

    expect(
      await screen.findByText(/Version 2026-10-18, in effect since 2026-10-18/),
    ).toBeInTheDocument()
    expect(screen.getByText("Data Protection Officer")).toBeInTheDocument()
    expect(screen.getByText("Jane Sample")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "dpo@grc.test" })).toHaveAttribute(
      "href",
      "mailto:dpo@grc.test",
    )
    expect(screen.getByText("02 8000 0000")).toBeInTheDocument()
  })

  it("names no officer when none has been published", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            ...infoPayload.data,
            contact: { name: null, email: "info@grc.edu.ph", phone: null },
          },
        }),
      ),
    )
    renderWithSession(<PrivacyNoticePage />, {
      session: null,
      status: "anonymous",
    })

    expect(
      await screen.findByRole("link", { name: "info@grc.edu.ph" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByText("Data Protection Officer"),
    ).not.toBeInTheDocument()
  })
})
