import { screen, within } from "@testing-library/react"
import {
  afterEach,
  beforeAll,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from "vitest"

import type { AuthSession } from "@/features/auth/auth-types"
import { userRoles, type UserRole } from "@/features/auth/roles"
import { PortalShell } from "@/features/components/layouts/portal-shell"
import { PortalModulePage } from "@/features/components/pages/portal-module-page"
import {
  isConnectedModuleId,
  preloadConnectedModules,
} from "@/features/portal/module-registry"
import { rolePortalDefinitions } from "@/features/portal/role-capabilities"
import { renderWithSession } from "@/tests/render-app"

function sessionFor(role: UserRole): AuthSession {
  return {
    userId: "1",
    displayName: `Test ${rolePortalDefinitions[role].roleLabel}`,
    role,
    signedInAt: "2026-07-26T12:00:00.000Z",
  }
}

/** Renders the module page inside the shell, as `app/portal/layout.tsx` does. */
function renderModule(role: UserRole, moduleId: string) {
  return renderWithSession(
    <PortalShell>
      <PortalModulePage moduleId={moduleId} />
    </PortalShell>,
    {
      route: `/portal/${moduleId}`,
      routeParams: { moduleId },
      session: sessionFor(role),
    },
  )
}

const allowedModuleCases = userRoles.flatMap((role) =>
  rolePortalDefinitions[role].modules.map((module) => ({ module, role })),
)

// Since Phase 8b (ADR 0015), a connected workspace's own WorkspacePage
// heading is the page's *only* heading — this shell no longer wraps it in a
// second, module-registry-sourced <h1>. Each workspace's heading text is its
// own, established in earlier phases and unaffected by this shell change; it
// is not always identical to the module registry's label (e.g.
// "Enrollment" vs. "Enrollment planning review" for `master-schedule`).
// Kept in sync with `module-registry.test.tsx`'s identical
// `migratedRegionNames` map, which covers the connected module IDs.
const workspaceHeadings: Record<string, string> = {
  "class-rosters": "Class rosters",
  "grade-submission": "Grade submission",
  "credit-mappings": "Credit mappings",
  "drops-withdrawals": "Drops & withdrawals",
  "academic-records": "Academic records",
  "enrollment-documents": "Enrollment documents",
  "master-schedule": "Enrollment planning review",
  "submitted-schedules": "Submitted schedules",
  "section-change-requests": "Schedule change requests",
  "faculty-load-monitoring": "Faculty load",
  "audit-logs": "Audit logs",
  "teaching-schedule": "Teaching schedule",
  grades: "Grades",
  "digital-com": "Certificate of Registration",
  "schedule-approvals": "Enrollment planning review",
  "curriculum-approvals": "Curriculum Approvals",
  "schedule-proposals": "Schedule proposals",
  schedule: "Schedule",
  "faculty-loading": "Faculty Loading",
  "faculty-workforce": "Faculty Workforce",
  "program-chair-analytics": "Enrollment Analytics",
  "faculty-invitations": "Invite Professors",
  "staff-invitations": "Invite Staff",
  "registrar-analytics": "Enrollment Analytics",
  rooms: "Rooms",
  enrollment: "Select your subjects",
  "enrollment-approvals": "Enrollment approvals",
  "program-chair-enrollment": "Enrollment",
  "subjects-prerequisites": "Curriculum editor",
  "academic-terms": "Enrollment",
  "availability-preferences": "Availability and preferences",
  "payment-queue": "Payment queue",
  "advance-payment": "Advance Payment",
  "statement-of-account": "Statement of Account",
  "admission-requirements": "Admission",
  "payment-records": "Transaction history",
  "cor-records": "Certificate of Registration Records",
  "queue-kiosk-access": "Queue kiosk access",
  "student-records": "Student Records",
  "student-information": "Student Information",
  "attrition-analytics": "Attrition analytics",
  honors: "Dean's list",
  "enrollment-dashboard": "Enrollment dashboard",
  "institution-dashboard": "Institution dashboard",
  "policy-settings": "Policy settings",
  "fee-settings": "Fee Settings",
  "payment-discount": "Payment Discount",
  "grade-approvals": "Grade approvals",
  "academic-transcripts": "Academic transcripts",
  "enrollment-change-requests": "Add/Drop requests",
  "enrollment-requests": "Enrollment requests",
  students: "Students",
  "subject-withdrawals": "Subject withdrawals",
  "it-control-students": "IT Control student accounts",
  "it-control-faculty": "IT Control faculty accounts",
  "irregular-enrollments": "Irregular Student Advising & Approvals",
  graduates: "Graduates directory",
  "ched-reports": "CHED Reports",
  "professor-information": "My Information",
}

describe("PortalModulePage", () => {
  // Workspaces are lazy chunks (ADR 0029): warm them all once so each case below
  // resolves from the module cache instead of transforming a workspace on demand.
  beforeAll(async () => {
    await preloadConnectedModules()
  }, 180_000)

  it.each(allowedModuleCases)(
    "renders $role access to $module.id from that role's catalog",
    async ({ module, role }) => {
      const definition = rolePortalDefinitions[role]
      renderModule(role, module.id)

      // The sidebar profile block always renders the role label, regardless
      // of which module is open — unaffected by the connected/placeholder
      // branch below.
      expect(screen.getAllByText(definition.roleLabel).length).toBeGreaterThan(
        0,
      )

      if (isConnectedModuleId(module.id)) {
        const heading = workspaceHeadings[module.id]
        expect(heading).toBeDefined()
        expect(
          await screen.findByRole(
            "heading",
            { level: 1, name: heading },
            { timeout: 15_000 },
          ),
        ).toBeInTheDocument()
        expect(
          screen.getByRole("region", { name: heading }),
        ).toBeInTheDocument()
        expect(
          screen.queryByRole("heading", { name: "Planned capability" }),
        ).not.toBeInTheDocument()
      } else {
        expect(
          screen.getByRole("heading", { level: 1, name: module.label }),
        ).toBeInTheDocument()
        expect(screen.getByText(module.description)).toBeInTheDocument()
        expect(
          screen.getByRole("region", {
            name: `${module.label} planned capability`,
          }),
        ).toBeInTheDocument()
        expect(
          screen.getByRole("heading", { name: "Planned capability" }),
        ).toBeInTheDocument()
        expect(
          screen.getByText(
            "This workspace is being prepared for a future GRC Connect release.",
          ),
        ).toBeInTheDocument()
      }
      expect(
        screen.getByRole("link", { name: "Return to GRC Connect" }),
      ).toHaveAttribute("href", "/portal")
    },
  )

  it("renders a scoped portal not-found state for an unknown module", () => {
    renderModule("student", "not-a-real-module")

    expect(
      screen.getByRole("heading", { name: "Portal module not found" }),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        "This destination is not assigned to your signed-in role.",
      ),
    ).toBeInTheDocument()

    const unavailableRegion = screen.getByRole("region", {
      name: "Unavailable portal module",
    })
    expect(unavailableRegion).not.toHaveTextContent("not-a-real-module")

    const breadcrumb = screen.getByRole("navigation", { name: "Breadcrumb" })
    expect(
      within(breadcrumb).getByText("Unavailable workspace"),
    ).toHaveAttribute("aria-current", "page")
  })

  it("does not leak a valid module assigned to another role", () => {
    const accountingModule = rolePortalDefinitions.accounting_staff.modules[0]
    renderModule("student", accountingModule.id)

    expect(
      screen.getByRole("heading", { name: "Portal module not found" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("heading", { name: accountingModule.label }),
    ).not.toBeInTheDocument()
    expect(document.body).not.toHaveTextContent(accountingModule.description)

    const navigation = screen.getByRole("navigation", {
      name: "Role portal navigation",
    })
    expect(
      within(navigation).queryByRole("link", { name: accountingModule.label }),
    ).not.toBeInTheDocument()
  })

  it("offers a route back to the overview that stays inside the signed-in role", () => {
    const studentModule = rolePortalDefinitions.student.modules[0]
    renderModule("student", studentModule.id)

    expect(
      screen.getByRole("link", { name: "Return to GRC Connect" }),
    ).toHaveAttribute("href", "/portal")

    // The destination must remain the role's own overview — the shell around
    // it still lists only the student catalog.
    const navigation = screen.getByRole("navigation", {
      name: "Role portal navigation",
    })
    const links = within(navigation).getAllByRole("link")
    expect(links).toHaveLength(
      rolePortalDefinitions.student.modules.length + 1, // + "GRC Connect"
    )
  })
})

describe("Curriculum Editor access for a Program Chair", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  function requestUrl(input: RequestInfo | URL): string {
    if (typeof input === "string") return input
    return input instanceof URL ? input.toString() : input.url
  }

  /** Academic term is active and the chair's college workflow sits in `stage`. */
  function mockWorkflowStage(stage: string) {
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
                  stage,
                  stage_label: stage,
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
  }

  it("stays open while the chair's college Enrollment workflow is still in an earlier stage", async () => {
    mockWorkflowStage("curriculum_preparation")

    renderWithSession(
      <PortalShell>
        <PortalModulePage moduleId="subjects-prerequisites" />
      </PortalShell>,
      {
        route: "/portal/subjects-prerequisites",
        routeParams: { moduleId: "subjects-prerequisites" },
        session: {
          userId: "9",
          displayName: "Test Program Chair",
          role: "program_chair",
          college: "ccs",
          signedInAt: "2026-08-07T00:00:00.000Z",
        },
      },
    )

    expect(
      await screen.findByRole("heading", {
        level: 1,
        name: "Curriculum editor",
      }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("heading", { name: "Enrollment step in progress" }),
    ).not.toBeInTheDocument()
  })
})
