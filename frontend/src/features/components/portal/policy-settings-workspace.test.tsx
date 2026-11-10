import { screen, within } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { PolicySettingsWorkspace } from "@/features/components/portal/policy-settings-workspace"
import { renderWithSession } from "@/tests/render-app"

const policySettings = {
  data: {
    type: "policy_settings_summary",
    values: [
      {
        key: "enrollment.max_regular_units",
        label: "Maximum regular units",
        current_value: null,
        status: "unset",
        status_label: "No value set",
        description: "Null means no cap is enforced.",
        prd_reference:
          "PRD §17 — Maximum regular units and overload approval workflow.",
      },
      {
        key: "sections.viability_threshold",
        label: "Section viability threshold",
        current_value: null,
        status: "no_mechanism",
        status_label: "No configuration mechanism yet",
        description:
          "A per-section nullable column, not an institution-wide default.",
        prd_reference:
          "PRD §17 — Section-viability threshold and exception authority.",
      },
    ],
  },
}

describe("PolicySettingsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("withholds policy settings from a non-registrar-head role", () => {
    renderWithSession(<PolicySettingsWorkspace />, {
      session: {
        userId: "5",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-07-31T00:00:00Z",
      },
    })
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("shows each policy value's configured/unset/no-mechanism status", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(policySettings))),
    )
    renderWithSession(<PolicySettingsWorkspace />, {
      session: {
        userId: "7",
        displayName: "Registrar Head",
        role: "registrar_head",
        signedInAt: "2026-07-31T00:00:00Z",
      },
    })

    const table = await screen.findByRole("table", { name: "Policy settings" })
    expect(within(table).getByText("Maximum regular units")).toBeInTheDocument()
    expect(within(table).getByText("No value set")).toBeInTheDocument()
    expect(
      within(table).getByText("No configuration mechanism yet"),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(policySettings))),
    )
    const { container } = renderWithSession(<PolicySettingsWorkspace />, {
      session: {
        userId: "7",
        displayName: "Registrar Head",
        role: "registrar_head",
        signedInAt: "2026-07-31T00:00:00Z",
      },
    })
    await screen.findByRole("table", { name: "Policy settings" })
    expect(await axe(container)).toHaveNoViolations()
  })
})
