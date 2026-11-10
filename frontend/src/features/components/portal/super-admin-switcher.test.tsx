import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import {
  SuperAdminSwitcher,
  SuperAdminBanner,
} from "@/features/components/portal/super-admin-switcher"
import { renderWithSession } from "@/tests/render-app"
import * as superAdminService from "@/features/services/super-admin-service"
import type { AuthenticatedUser } from "@/features/schemas/auth-schema"
import { agreedPrivacyNotice } from "@/tests/privacy-notice-fixture"

describe("SuperAdminSwitcher and SuperAdminBanner", () => {
  it("does not render switcher or banner for non-super-admin users", () => {
    renderWithSession(
      <div>
        <SuperAdminSwitcher />
        <SuperAdminBanner />
      </div>,
      {
        session: {
          userId: "2",
          displayName: "Staff Member",
          role: "registrar_head",
          signedInAt: "2026-10-01T00:00:00Z",
        },
      },
    )

    expect(screen.queryByRole("button", { name: "Switch department" })).not.toBeInTheDocument()
    expect(screen.queryByRole("status")).not.toBeInTheDocument()
  })

  it("lists all 8 offices and conditionally displays college select only for Program Head and Dean", async () => {
    const user = userEvent.setup()

    renderWithSession(
      <div>
        <SuperAdminSwitcher />
      </div>,
      {
        session: {
          userId: "1",
          displayName: "Westlie Casuncad",
          role: "super_admin",
          signedInAt: "2026-10-01T00:00:00Z",
          superAdmin: { actingContext: null },
        },
      },
    )

    const switchBtn = screen.getByRole("button", { name: "Switch department" })
    await user.click(switchBtn)

    expect(screen.getByRole("dialog", { name: "Switch Department" })).toBeInTheDocument()

    const officeSelect = screen.getByLabelText("Department / Office")
    expect(officeSelect).toBeInTheDocument()

    // 8 offices check
    expect(screen.getByRole("option", { name: "Admission Staff" })).toBeInTheDocument()
    expect(screen.getByRole("option", { name: "Program Head" })).toBeInTheDocument()
    expect(screen.getByRole("option", { name: "Dean" })).toBeInTheDocument()
    expect(screen.getByRole("option", { name: "Executive Director" })).toBeInTheDocument()
    expect(screen.getByRole("option", { name: "Registrar Head" })).toBeInTheDocument()
    expect(screen.getByRole("option", { name: "Registrar Staff" })).toBeInTheDocument()
    expect(screen.getByRole("option", { name: "Accounting Staff" })).toBeInTheDocument()
    expect(screen.getByRole("option", { name: "IT Control" })).toBeInTheDocument()

    // Dean is initial default: college select should be visible
    expect(screen.getByLabelText("College")).toBeInTheDocument()

    // Switch to Registrar Head: college select must disappear
    await user.selectOptions(officeSelect, "registrar_head")
    expect(screen.queryByLabelText("College")).not.toBeInTheDocument()

    // Switch to Program Head: college select reappears
    await user.selectOptions(officeSelect, "program_chair")
    expect(screen.getByLabelText("College")).toBeInTheDocument()
  })

  it("submits switch request with role and college and closes dialog", async () => {
    const user = userEvent.setup()
    const switchSpy = vi.spyOn(superAdminService, "switchActingContext").mockResolvedValueOnce({
      data: {
        type: "user",
        id: 1,
        name: "Westlie Casuncad",
        email: "westlie@grc.edu.ph",
        role: "dean",
        role_label: "Dean",
        privacy_notice: agreedPrivacyNotice,
        college: "ccs",
        status: "active",
        acting_context: { role: "dean", college: "ccs" },
      } as AuthenticatedUser,
    })

    const replaceSession = vi.fn()

    renderWithSession(
      <div>
        <SuperAdminSwitcher />
      </div>,
      {
        session: {
          userId: "1",
          displayName: "Westlie Casuncad",
          role: "super_admin",
          signedInAt: "2026-10-01T00:00:00Z",
          superAdmin: { actingContext: null },
        },
        replaceSession,
      },
    )

    await user.click(screen.getByRole("button", { name: "Switch department" }))
    await user.click(screen.getByRole("button", { name: "Switch Workspace" }))

    await waitFor(() => {
      expect(switchSpy).toHaveBeenCalledWith({
        role: "dean",
        college: "ccs",
      })
    })

    expect(replaceSession).toHaveBeenCalled()
  })

  it("renders SuperAdminBanner when acting and allows exiting back to console", async () => {
    const user = userEvent.setup()
    const clearSpy = vi.spyOn(superAdminService, "clearActingContext").mockResolvedValueOnce({
      data: {
        type: "user",
        id: 1,
        name: "Westlie Casuncad",
        email: "westlie@grc.edu.ph",
        role: "super_admin",
        role_label: "Super Admin",
        privacy_notice: agreedPrivacyNotice,
        college: null,
        status: "active",
        acting_context: null,
      } as AuthenticatedUser,
    })

    const replaceSession = vi.fn()

    renderWithSession(
      <div>
        <SuperAdminBanner />
      </div>,
      {
        session: {
          userId: "1",
          displayName: "Westlie Casuncad",
          role: "dean",
          college: "ccs",
          signedInAt: "2026-10-01T00:00:00Z",
          superAdmin: { actingContext: { role: "dean", college: "ccs" } },
        },
        replaceSession,
      },
    )

    const banner = screen.getByRole("status")
    expect(banner).toBeInTheDocument()
    expect(banner).toHaveTextContent("Super Admin · Acting as Dean (CCS)")

    const exitBtn = screen.getByRole("button", { name: /Back to Admin Console/i })
    await user.click(exitBtn)

    await waitFor(() => {
      expect(clearSpy).toHaveBeenCalled()
    })
    expect(replaceSession).toHaveBeenCalled()
  })

  it("passes axe accessibility checks", async () => {
    const { container } = renderWithSession(
      <div>
        <SuperAdminSwitcher />
        <SuperAdminBanner />
      </div>,
      {
        session: {
          userId: "1",
          displayName: "Westlie Casuncad",
          role: "dean",
          college: "ccs",
          signedInAt: "2026-10-01T00:00:00Z",
          superAdmin: { actingContext: { role: "dean", college: "ccs" } },
        },
      },
    )

    expect(await axe(container)).toHaveNoViolations()
  })
})
