import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { PaymentDiscountWorkspace } from "@/features/components/portal/payment-discount-workspace"
import { renderWithSession } from "@/tests/render-app"

const registrarHead = {
  userId: "head-1",
  displayName: "Registrar Head",
  role: "registrar_head" as const,
  signedInAt: "2026-10-12T00:00:00Z",
}

const setting = {
  type: "payment_discount_setting",
  percentage: "10.0",
  valid_from: null,
  valid_until: null,
  status: "active",
  updated_by: null,
  updated_at: null,
} as const

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

describe("PaymentDiscountWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  const puts = () =>
    (fetchMock.mock.calls as [RequestInfo | URL, RequestInit | undefined][]).filter(
      ([input, init]) =>
        requestUrl(input).includes("/payment-discount-settings") && init?.method === "PUT",
    )

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input, init) => {
      if (requestUrl(input).includes("/payment-discount-settings")) {
        if (init?.method === "PUT") {
          const sent = JSON.parse(init.body as string) as Record<string, unknown>
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: { ...setting, ...sent, status: "active", updated_by: "Registrar Head", updated_at: "2026-10-12T01:00:00Z" },
              }),
            ),
          )
        }
        return Promise.resolve(new Response(JSON.stringify({ data: setting })))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
  })
  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  it("shows the current percentage and an Active badge, with Save disabled until something changes", async () => {
    renderWithSession(<PaymentDiscountWorkspace />, { session: registrarHead })

    expect(await screen.findByLabelText("Discount percentage")).toHaveValue("10.0")
    expect(screen.getByText("Active")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Save discount" })).toBeDisabled()
  })

  it("saves a new percentage and window, sending an empty date as null", async () => {
    const user = userEvent.setup()
    renderWithSession(<PaymentDiscountWorkspace />, { session: registrarHead })

    const percentage = await screen.findByLabelText("Discount percentage")
    await user.clear(percentage)
    await user.type(percentage, "12.5")
    await user.type(screen.getByLabelText("Valid until"), "2026-12-31")
    await user.click(screen.getByRole("button", { name: "Save discount" }))

    await waitFor(() => expect(puts()).toHaveLength(1))
    expect(JSON.parse(puts()[0][1]?.body as string)).toEqual({
      percentage: "12.5",
      valid_from: null,
      valid_until: "2026-12-31",
    })
    expect(await screen.findByText(/Discount saved/)).toBeInTheDocument()
  })

  it("refuses a percentage over 100 or with two decimals and an end date before the start", async () => {
    const user = userEvent.setup()
    renderWithSession(<PaymentDiscountWorkspace />, { session: registrarHead })

    const percentage = await screen.findByLabelText("Discount percentage")
    await user.clear(percentage)
    await user.type(percentage, "100.5")
    await user.click(screen.getByRole("button", { name: "Save discount" }))
    expect(await screen.findByText("The percentage cannot be more than 100.")).toBeInTheDocument()

    await user.clear(percentage)
    await user.type(percentage, "10.55")
    await user.click(screen.getByRole("button", { name: "Save discount" }))
    expect(
      await screen.findByText("Enter a percentage with at most one decimal, for example 10 or 12.5."),
    ).toBeInTheDocument()

    await user.clear(percentage)
    await user.type(percentage, "10")
    await user.type(screen.getByLabelText("Valid from"), "2026-10-20")
    await user.type(screen.getByLabelText("Valid until"), "2026-10-10")
    await user.click(screen.getByRole("button", { name: "Save discount" }))
    expect(await screen.findByText("The end date cannot be before the start date.")).toBeInTheDocument()

    expect(puts()).toHaveLength(0)
  })

  it("is only for the Registrar Head and makes no request for another role", () => {
    renderWithSession(<PaymentDiscountWorkspace />, {
      session: { ...registrarHead, role: "accounting_staff" },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
