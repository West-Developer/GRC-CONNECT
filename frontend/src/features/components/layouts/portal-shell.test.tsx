import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import type { AuthSession } from "@/features/auth/auth-types"
import { userRoles, type UserRole } from "@/features/auth/roles"
import { PortalShell } from "@/features/components/layouts/portal-shell"
import { PortalModulePage } from "@/features/components/pages/portal-module-page"
import { PortalOverviewPage } from "@/features/components/pages/portal-overview-page"
import { SIDEBAR_COLLAPSED_STORAGE_KEY } from "@/features/hooks/use-sidebar-collapsed"
import { guideRoles } from "@/features/lib/user-guide/roles"
import { isConnectedModuleId } from "@/features/portal/module-registry"
import { rolePortalDefinitions } from "@/features/portal/role-capabilities"
import { routerMock } from "@/tests/navigation-mock"
import { renderWithSession } from "@/tests/render-app"

function sessionFor(role: UserRole): AuthSession {
  return {
    userId: "1",
    displayName: `Test ${rolePortalDefinitions[role].roleLabel}`,
    role,
    signedInAt: "2026-07-26T12:00:00.000Z",
  }
}

function renderShell(role: UserRole, overrides = {}) {
  return renderWithSession(
    <PortalShell>
      <PortalOverviewPage />
    </PortalShell>,
    { route: "/portal", session: sessionFor(role), ...overrides },
  )
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") {
    return input
  }

  return input instanceof URL ? input.toString() : input.url
}

describe("PortalShell", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    fetchMock.mockImplementation(() => new Promise<Response>(() => undefined))
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it.each(userRoles)("shows only live role navigation for %s", (role) => {
    const definition = rolePortalDefinitions[role]
    // A nested module (the Program Head's Irregular Advising and Credit Mappings) has no sidebar entry: its
    // page is reached from its parent's page.
    const liveModules = definition.modules.filter(
      (module) =>
        isConnectedModuleId(module.id) && module.nestedUnder === undefined,
    )
    renderShell(role)

    expect(
      screen.getByRole("heading", { name: "GRC Connect" }),
    ).toBeInTheDocument()

    const navigation = screen.getByRole("navigation", {
      name: "Role portal navigation",
    })
    const links = within(navigation).getAllByRole("link")

    expect(links).toHaveLength(liveModules.length + 1)
    expect(
      within(navigation).getByRole("link", { name: "GRC Connect" }),
    ).toHaveAttribute("href", "/portal")

    for (const module of definition.modules) {
      const link = within(navigation).queryByRole("link", {
        name: module.label,
      })

      if (isConnectedModuleId(module.id) && module.nestedUnder === undefined) {
        expect(link).toHaveAttribute("href", `/portal/${module.id}`)
      } else {
        expect(link).not.toBeInTheDocument()
      }
    }

    expect(
      screen.getAllByText(sessionFor(role).displayName).length,
    ).toBeGreaterThan(0)
    expect(screen.getAllByText(definition.roleLabel).length).toBeGreaterThan(0)
  })

  it("keeps the top bar focused on connected account actions", () => {
    renderShell("student")

    expect(screen.getByRole("button", { name: /notifications/i })).toBeEnabled()

    for (const name of [
      "Profile preview",
      "Password settings preview",
      "Help preview",
      "Report issue preview",
    ]) {
      expect(screen.queryByRole("button", { name })).not.toBeInTheDocument()
    }
  })

  it.each(guideRoles)(
    "links %s to their own chapter of the user guide",
    (role) => {
      renderShell(role)

      expect(screen.getByRole("link", { name: "User guide" })).toHaveAttribute(
        "href",
        `/user-guide?role=${role}`,
      )
    },
  )

  it.each(["it_admin", "queue_kiosk", "super_admin"] as const)(
    "does not offer the user guide to %s",
    (role) => {
      renderShell(role)

      expect(
        screen.queryByRole("link", { name: "User guide" }),
      ).not.toBeInTheDocument()
    },
  )

  it("shows the active academic term and falls back when none is active", async () => {
    fetchMock.mockImplementation((input) => {
      if (requestUrl(input).includes("/academic-terms")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "academic-term",
                  id: 1,
                  school_year: "2026-2027",
                  semester: "1st",
                  starts_at: null,
                  ends_at: null,
                  enrollment_opens_at: null,
                  enrollment_closes_at: null,
                  add_drop_deadline_at: null,
                  grading_deadline_at: null,
                  status: "semester_ongoing",
                  status_label: "Semester Ongoing",
                },
              ],
            }),
            { status: 200 },
          ),
        )
      }

      return new Promise<Response>(() => undefined)
    })
    renderShell("student")
    expect(
      (await screen.findAllByText("2026-2027 · 1st")).length,
    ).toBeGreaterThan(0)

    fetchMock.mockImplementation((input) => {
      if (requestUrl(input).includes("/academic-terms")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "academic-term",
                  id: 2,
                  school_year: "2026-2027",
                  semester: "2nd",
                  starts_at: null,
                  ends_at: null,
                  enrollment_opens_at: null,
                  enrollment_closes_at: null,
                  add_drop_deadline_at: null,
                  grading_deadline_at: null,
                  status: "draft",
                  status_label: "Draft",
                },
              ],
            }),
            { status: 200 },
          ),
        )
      }

      return new Promise<Response>(() => undefined)
    })
    renderShell("faculty")
    expect(
      (await screen.findAllByText("No active academic term")).length,
    ).toBeGreaterThan(0)
  })

  it("opens an accessible mobile navigation Sheet", async () => {
    const user = userEvent.setup()
    renderShell("accounting_staff")

    await user.click(
      screen.getByRole("button", { name: "Open portal navigation" }),
    )

    const dialog = screen.getByRole("dialog", {
      name: "GRC Connect navigation",
    })
    expect(
      within(dialog).getByRole("link", { name: "Payment Queue" }),
    ).toHaveAttribute("href", "/portal/payment-queue")
    expect(
      within(dialog).getByRole("button", { name: "Close" }),
    ).toBeInTheDocument()
  })

  it("moves Sign out into the bottom of the navigation drawer for phones", async () => {
    // Stakeholder Doc 13: on a phone the bell takes the top bar's right-hand
    // spot and Sign out lives at the very bottom of the hamburger menu.
    const user = userEvent.setup()
    const signOut = vi.fn()
    renderShell("student", { signOut })

    // The top bar keeps its own Sign out for desktop; the class is the hook the
    // tablet/phone stylesheet uses to hide it while the drawer exists.
    expect(screen.getByRole("button", { name: "Sign out" })).toHaveClass(
      "portal-topbar__signout",
    )

    await user.click(
      screen.getByRole("button", { name: "Open portal navigation" }),
    )
    const drawer = screen.getByRole("dialog", {
      name: "GRC Connect navigation",
    })
    const drawerNavigation = within(drawer).getByRole("navigation", {
      name: "Mobile role portal navigation",
    })
    const drawerSignOut = within(drawer).getByRole("button", {
      name: "Sign out",
    })

    // After the links, i.e. at the bottom of the menu.
    expect(
      drawerNavigation.compareDocumentPosition(drawerSignOut) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()

    await user.click(drawerSignOut)

    expect(routerMock.replace).toHaveBeenCalledWith("/")
    expect(signOut).toHaveBeenCalled()
  })

  it("signs out by navigating home before clearing the session", async () => {
    const user = userEvent.setup()
    const signOut = vi.fn()
    renderShell("student", { signOut })

    await user.click(screen.getByRole("button", { name: "Sign out" }))

    // Order matters: navigating first is what stops RequireSession from
    // bouncing a now-anonymous user to /login instead of the landing page.
    expect(routerMock.replace).toHaveBeenCalledWith("/")
    expect(signOut).toHaveBeenCalled()
  })

  it("warns when the session cannot be persisted on this browser", () => {
    renderShell("student", { storageAvailable: false })

    expect(
      screen.getByText(
        "Your session cannot be restored after refresh on this browser.",
      ),
    ).toBeInTheDocument()
  })

  it("labels the signed-in workspace as GRC Connect", () => {
    renderShell("student")

    expect(screen.getAllByText("GRC Connect").length).toBeGreaterThan(0)
    expect(screen.queryByText("Demo portal")).not.toBeInTheDocument()
  })

  it("marks the active nav link with aria-current", () => {
    renderShell("student")

    const navigation = screen.getByRole("navigation", {
      name: "Role portal navigation",
    })

    expect(
      within(navigation).getByRole("link", { name: "GRC Connect" }),
    ).toHaveAttribute("aria-current", "page")
  })

  it("shows a single current-page breadcrumb on the overview", () => {
    renderShell("student")

    const breadcrumb = screen.getByRole("navigation", { name: "Breadcrumb" })
    expect(within(breadcrumb).getByText("GRC Connect")).toHaveAttribute(
      "aria-current",
      "page",
    )
    expect(within(breadcrumb).queryByRole("link")).not.toBeInTheDocument()
  })

  it("shows a two-level breadcrumb linking back to the overview from a module", () => {
    const module = rolePortalDefinitions.student.modules[0]
    renderWithSession(
      <PortalShell>
        <PortalModulePage moduleId={module.id} />
      </PortalShell>,
      {
        route: `/portal/${module.id}`,
        routeParams: { moduleId: module.id },
        session: sessionFor("student"),
      },
    )

    const breadcrumb = screen.getByRole("navigation", { name: "Breadcrumb" })
    expect(
      within(breadcrumb).getByRole("link", { name: "GRC Connect" }),
    ).toHaveAttribute("href", "/portal")
    expect(within(breadcrumb).getByText(module.label)).toHaveAttribute(
      "aria-current",
      "page",
    )
  })

  it("never greys out a Program Chair link, whatever stage the term workflow is in", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/academic-term-workflows")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "academic-term-workflow",
                  id: 1,
                  academic_term_id: 5,
                  college: "ccs",
                  college_label: "College of Computer Studies",
                  stage: "curriculum_preparation",
                  stage_label: "Curriculum Preparation",
                  curriculum_completed_at: null,
                  faculty_reviewed_at: null,
                  schedule_submitted_at: null,
                },
              ],
            }),
          ),
        )
      }
      if (url.includes("/academic-terms")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "academic-term",
                  id: 5,
                  school_year: "2026-2027",
                  semester: "1st",
                  starts_at: null,
                  ends_at: null,
                  enrollment_opens_at: null,
                  enrollment_closes_at: null,
                  add_drop_deadline_at: null,
                  grading_deadline_at: null,
                  status: "semester_ongoing",
                  status_label: "Semester Ongoing",
                },
              ],
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderShell("program_chair", {
      session: { ...sessionFor("program_chair"), college: "ccs" },
    })

    const navigation = screen.getByRole("navigation", {
      name: "Role portal navigation",
    })
    expect(
      await within(navigation).findByRole("link", {
        name: "Curriculum Editor",
      }),
    ).not.toHaveAttribute("aria-disabled")

    // Stakeholder Doc 12: Schedule looked greyed out yet was clickable. The
    // "curriculum_preparation" stage used to lock it; no stage may now.
    const schedule = within(navigation).getByRole("link", { name: "Schedule" })
    expect(schedule).not.toHaveAttribute("aria-disabled")
    expect(schedule).not.toHaveAttribute("title")
    expect(schedule).not.toHaveClass("portal-nav-link--locked")
    expect(schedule).toHaveAttribute("href", "/portal/schedule")

    for (const link of within(navigation).getAllByRole("link")) {
      expect(link).not.toHaveAttribute("aria-disabled")
    }
  })

  it("keeps Irregular Advising and Credit Mappings out of the Program Chair's sidebar, inside Enrollment", () => {
    renderShell("program_chair", {
      session: { ...sessionFor("program_chair"), college: "ccs" },
    })

    const navigation = screen.getByRole("navigation", {
      name: "Role portal navigation",
    })
    const names = within(navigation)
      .getAllByRole("link")
      .map((link) => link.textContent?.trim())

    expect(names.slice(0, 4)).toEqual([
      "GRC Connect",
      "Enrollment",
      "Enrollment Dashboard",
      "Enrollment Analytics",
    ])
    expect(names).not.toContain("Irregular Advising")
    expect(names).not.toContain("Credit Mappings")
  })

  it("shows the Program Chair how many are waiting on the Enrollment entry", async () => {
    fetchMock.mockImplementation((input) => {
      const url =
        typeof input === "string"
          ? input
          : input instanceof URL
            ? input.toString()
            : input.url
      if (url.includes("/api/v1/program-chair/review-counts")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "program_chair_review_counts",
                irregular_advising: 2,
                credit_mappings: 3,
              },
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderShell("program_chair", {
      session: { ...sessionFor("program_chair"), college: "ccs" },
    })

    const navigation = screen.getByRole("navigation", {
      name: "Role portal navigation",
    })
    const enrollment = await within(navigation).findByRole("link", {
      name: /^Enrollment5 waiting for your review$/,
    })
    expect(enrollment).toHaveAttribute(
      "href",
      "/portal/program-chair-enrollment",
    )
  })

  describe("foldable sidebar", () => {
    beforeEach(() => {
      window.localStorage.removeItem(SIDEBAR_COLLAPSED_STORAGE_KEY)
    })

    afterEach(() => {
      vi.restoreAllMocks()
      window.localStorage.removeItem(SIDEBAR_COLLAPSED_STORAGE_KEY)
    })

    it.each(userRoles)(
      "offers an expanded, foldable sidebar for %s",
      (role) => {
        renderShell(role)

        expect(
          screen.getByRole("button", { name: "Collapse sidebar" }),
        ).toHaveAttribute("aria-expanded", "true")
        expect(document.querySelector(".portal-app")).toHaveAttribute(
          "data-sidebar",
          "expanded",
        )
      },
    )

    it("folds to an icon rail, keeps every link named, and remembers the choice", async () => {
      const user = userEvent.setup()
      renderShell("student")

      const navigation = screen.getByRole("navigation", {
        name: "Role portal navigation",
      })
      const namesBefore = within(navigation)
        .getAllByRole("link")
        .map((link) => link.textContent?.trim())

      await user.click(screen.getByRole("button", { name: "Collapse sidebar" }))

      const expand = screen.getByRole("button", { name: "Expand sidebar" })
      expect(expand).toHaveAttribute("aria-expanded", "false")
      expect(expand).toHaveAttribute("aria-controls", "portal-sidebar")
      expect(document.querySelector(".portal-app")).toHaveAttribute(
        "data-sidebar",
        "collapsed",
      )
      // Labels leave the layout but never the DOM, so links keep their names
      // while only icons are visible — the hover hint itself is a pure-CSS
      // wipe-reveal of that same label (see globals.css), not a native
      // `title` attribute (that would pop its own OS-styled tooltip on top
      // of the CSS pill, a real duplicate the owner flagged live).
      expect(
        within(navigation)
          .getAllByRole("link")
          .map((link) => link.textContent?.trim()),
      ).toEqual(namesBefore)
      expect(
        within(navigation).getByRole("link", { name: "GRC Connect" }),
      ).not.toHaveAttribute("title")
      expect(window.localStorage.getItem(SIDEBAR_COLLAPSED_STORAGE_KEY)).toBe(
        "1",
      )

      await user.click(expand)

      expect(
        screen.getByRole("button", { name: "Collapse sidebar" }),
      ).toHaveAttribute("aria-expanded", "true")
      expect(
        within(navigation).getByRole("link", { name: "GRC Connect" }),
      ).not.toHaveAttribute("title")
      expect(window.localStorage.getItem(SIDEBAR_COLLAPSED_STORAGE_KEY)).toBe(
        "0",
      )
    })

    it("starts folded when the choice was saved earlier", () => {
      window.localStorage.setItem(SIDEBAR_COLLAPSED_STORAGE_KEY, "1")
      renderShell("registrar_head")

      expect(
        screen.getByRole("button", { name: "Expand sidebar" }),
      ).toHaveAttribute("aria-expanded", "false")
      expect(document.querySelector(".portal-app")).toHaveAttribute(
        "data-sidebar",
        "collapsed",
      )
    })

    it("still folds for the session when storage is blocked", async () => {
      vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
        throw new Error("storage blocked")
      })
      vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
        throw new Error("storage blocked")
      })
      const user = userEvent.setup()
      renderShell("dean")

      await user.click(screen.getByRole("button", { name: "Collapse sidebar" }))

      expect(
        screen.getByRole("button", { name: "Expand sidebar" }),
      ).toHaveAttribute("aria-expanded", "false")
    })
  })

  // A newly created Transferee or Returnee sees only Enrollment and Grades until they are enrolled and have paid
  // (ADR 0048, amended by ADR 0050).
  describe("a student with an enrollment deadline", () => {
    const studentProfile = {
      type: "student_profile",
      id: 31,
      user_id: 41,
      student_number: "2026-10-00031",
      name: "Tina Transferee",
      first_name: "Tina",
      middle_initial: null,
      last_name: "Transferee",
      suffix: null,
      email: "tina@grc.test",
      address: "Caloocan City",
      program_id: 11,
      program_code: "BSIT",
      program_name: "Bachelor of Science in Information Technology",
      curriculum_id: 22,
      entry_year: 2026,
      curriculum_name: "BSIT 2026 Curriculum",
      curriculum_effective_school_year: "2026-2027",
      year_level: 1,
      enrollment_category: "regular",
      student_type: "transferee",
      student_type_label: "Transferee",
      admission_status: "admitted",
      admission_status_label: "Admitted",
      academic_standing: "good",
      academic_standing_label: "Good Standing",
      financial_status: null,
      financial_status_label: null,
      requirements_verified_at: "2026-10-14T08:00:00Z",
      academic_setup_editable: true,
      account_setup_status: "active",
      invitation_delivery_status: "sent",
      freshman_payment_confirmed_at: null,
      freshman_status: null,
      advance_payment_held: false,
      queue_ticket_number: null,
      queue_link_delivery_status: null,
    }

    /** Serves the student's own profile; everything else never answers. */
    function serveProfile(enrollmentDeadlineAt: string | null | Error) {
      fetchMock.mockImplementation((input) => {
        if (!requestUrl(input).endsWith("/api/v1/student-profile")) {
          return new Promise<Response>(() => undefined)
        }

        return Promise.resolve(
          enrollmentDeadlineAt instanceof Error
            ? new Response("{}", { status: 500 })
            : new Response(
                JSON.stringify({
                  data: {
                    ...studentProfile,
                    enrollment_deadline_at: enrollmentDeadlineAt,
                  },
                }),
                { status: 200 },
              ),
        )
      })
    }

    const inTwoAndAHalfDays = () =>
      new Date(Date.now() + 2.5 * 24 * 60 * 60 * 1000).toISOString()

    function renderAt(route: string, moduleId?: string) {
      return renderWithSession(
        <PortalShell>
          <p>Page content</p>
        </PortalShell>,
        {
          route,
          routeParams: moduleId ? { moduleId } : {},
          session: sessionFor("student"),
        },
      )
    }

    it("shows Enrollment and Grades in the sidebar, with no home link and none of the other options", async () => {
      serveProfile(inTwoAndAHalfDays())
      renderAt("/portal/enrollment", "enrollment")

      const navigation = screen.getByRole("navigation", {
        name: "Role portal navigation",
      })
      await waitFor(() =>
        expect(
          within(navigation).queryByRole("link", { name: "Schedule" }),
        ).not.toBeInTheDocument(),
      )

      const links = within(navigation).getAllByRole("link")
      expect(links.map((link) => link.getAttribute("href"))).toEqual([
        "/portal/enrollment",
        "/portal/grades",
      ])
      expect(
        within(navigation).queryByRole("link", { name: "GRC Connect" }),
      ).not.toBeInTheDocument()
    })

    it("tells them the account will be deactivated unless the enrollment is finished and paid", async () => {
      serveProfile(inTwoAndAHalfDays())
      renderAt("/portal/enrollment", "enrollment")

      expect(await screen.findByRole("status")).toHaveTextContent(
        /This account will be deactivated in 3 days/,
      )
    })

    it("sends them to Enrollment when they open any other page, without showing it", async () => {
      serveProfile(inTwoAndAHalfDays())
      renderAt("/portal/schedule", "schedule")

      await waitFor(() =>
        expect(routerMock.replace).toHaveBeenCalledWith("/portal/enrollment"),
      )
      expect(screen.queryByText("Page content")).not.toBeInTheDocument()
    })

    it("lets them open Grades, where the Prospectus and the credited grades are", async () => {
      serveProfile(inTwoAndAHalfDays())
      renderAt("/portal/grades", "grades")

      await screen.findByRole("status")
      expect(routerMock.replace).not.toHaveBeenCalled()
      expect(screen.getByText("Page content")).toBeInTheDocument()
    })

    it("sends them from the GRC Connect home to Enrollment", async () => {
      serveProfile(inTwoAndAHalfDays())
      renderAt("/portal")

      await waitFor(() =>
        expect(routerMock.replace).toHaveBeenCalledWith("/portal/enrollment"),
      )
    })

    it("lets them stay on Enrollment", async () => {
      serveProfile(inTwoAndAHalfDays())
      renderAt("/portal/enrollment", "enrollment")

      await screen.findByRole("status")
      expect(routerMock.replace).not.toHaveBeenCalled()
      expect(screen.getByText("Page content")).toBeInTheDocument()
    })

    it("shows every student option and no banner once the deadline is gone", async () => {
      serveProfile(null)
      renderAt("/portal/grades", "grades")

      await waitFor(() => expect(fetchMock).toHaveBeenCalled())
      const navigation = screen.getByRole("navigation", {
        name: "Role portal navigation",
      })
      expect(
        within(navigation).getByRole("link", { name: "Schedule" }),
      ).toBeInTheDocument()
      expect(
        within(navigation).getByRole("link", { name: "GRC Connect" }),
      ).toBeInTheDocument()
      expect(screen.queryByRole("status")).not.toBeInTheDocument()
      expect(routerMock.replace).not.toHaveBeenCalled()
    })

    it("does not lock a student out when the profile cannot be read", async () => {
      serveProfile(new Error("profile unavailable"))
      renderAt("/portal/grades", "grades")

      await waitFor(() => expect(fetchMock).toHaveBeenCalled())
      expect(
        within(
          screen.getByRole("navigation", { name: "Role portal navigation" }),
        ).getByRole("link", { name: "Schedule" }),
      ).toBeInTheDocument()
      expect(routerMock.replace).not.toHaveBeenCalled()
    })
  })
})
