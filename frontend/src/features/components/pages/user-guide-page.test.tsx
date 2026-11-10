import { render, screen, within } from "@testing-library/react"
import { describe, expect, it } from "vitest"
import { axe } from "vitest-axe"

import { UserGuidePage } from "@/features/components/pages/user-guide-page"
import { guideRoles } from "@/features/lib/user-guide"
import { rolePortalDefinitions } from "@/features/portal/role-capabilities"
import { setLocation } from "@/tests/navigation-mock"

function renderGuide(url: string) {
  setLocation(url)
  return render(<UserGuidePage />)
}

describe("UserGuidePage", () => {
  it("opens without a sign-in and offers the complete PDF and a way to choose a role", () => {
    renderGuide("/user-guide")

    expect(
      screen.getByRole("heading", { level: 1, name: "How to use GRC Connect" }),
    ).toBeInTheDocument()
    const download = screen.getByRole("link", {
      name: /Download the complete PDF/,
    })
    expect(download).toHaveAttribute(
      "href",
      "/downloads/grc-enrollment-system-user-manual-and-guide.pdf",
    )
    expect(download).toHaveAttribute("download")
    expect(
      screen.getByRole("link", { name: "Sign in to portal" }),
    ).toHaveAttribute("href", "/login")
  })

  it("lists the nine roles as choices and leaves out IT Control and Super Admin", () => {
    renderGuide("/user-guide")

    const contents = screen.getByRole("navigation", {
      name: /Choose your role/,
    })
    for (const role of guideRoles) {
      const label = rolePortalDefinitions[role].roleLabel
      const link = within(contents)
        .getAllByRole("link")
        .find((candidate) => candidate.textContent?.startsWith(label))
      expect(link, label).toBeDefined()
      expect(link).toHaveAttribute("href", `/user-guide?role=${role}#${role}`)
    }
    expect(within(contents).queryByText(/IT Control/i)).not.toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/super admin/i)
  })

  it("shows everyone's chapter first and no role chapter until a role is chosen", () => {
    const { container } = renderGuide("/user-guide")

    expect(container.querySelector("#start")).not.toBeNull()
    expect(container.querySelector("#student")).toBeNull()
    expect(
      screen.getByRole("link", {
        name: /read every role.s guide on one page/i,
      }),
    ).toHaveAttribute("href", "/user-guide?role=all#contents")
  })

  it("shows the chosen role's chapter with its own PDF", () => {
    const { container } = renderGuide("/user-guide?role=accounting_staff")

    expect(container.querySelector("#start")).not.toBeNull()
    expect(container.querySelector("#accounting_staff")).not.toBeNull()
    expect(container.querySelector("#student")).toBeNull()
    expect(
      screen.getByRole("heading", { level: 2, name: "Accounting Staff" }),
    ).toBeInTheDocument()
    // One in the page's opening and one in the chapter's own header.
    const downloads = screen.getAllByRole("link", {
      name: "Download the Accounting Staff PDF",
    })
    expect(downloads.length).toBeGreaterThan(0)
    for (const link of downloads) {
      expect(link).toHaveAttribute(
        "href",
        "/downloads/grc-enrollment-system-user-manual-and-guide-accounting-staff.pdf",
      )
    }
    const current = screen
      .getAllByRole("link")
      .filter((link) => link.getAttribute("aria-current") === "true")
    expect(current).toHaveLength(1)
  })

  it("shows every chapter when asked for all roles", () => {
    const { container } = renderGuide("/user-guide?role=all")

    expect(container.querySelectorAll("article.guide-chapter")).toHaveLength(
      guideRoles.length + 1,
    )
  })

  it("ignores a role it does not document", () => {
    const { container } = renderGuide("/user-guide?role=super_admin")

    expect(container.querySelector("#start")).not.toBeNull()
    expect(container.querySelectorAll("article.guide-chapter")).toHaveLength(1)
  })

  it("has no accessibility violations for the chosen role's guide", async () => {
    const { container } = renderGuide("/user-guide?role=student")

    expect(await axe(container)).toHaveNoViolations()
  })
})
