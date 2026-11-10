import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  connectedModuleIds,
  connectedModuleRegistry,
  preloadConnectedModules,
} from "@/features/portal/module-registry"
import {
  getRoleModule,
  knownPortalModuleIds,
  rolePortalDefinitions,
} from "@/features/portal/role-capabilities"
import { renderWithSession } from "@/tests/render-app"

// Modules whose workspace component has been migrated onto WorkspacePage
// (Phase 8a Task 5) derive their region's accessible name directly from the
// visible <h2> heading text, not a separate aria-label string with a
// "workspace" suffix — so their expected name is exactly the heading text.
const migratedRegionNames: Partial<Record<string, string>> = {
  "class-rosters": "Class rosters",
  "grade-submission": "Grade submission",
  "credit-mappings": "Credit mappings",
  "drops-withdrawals": "Drops & withdrawals",
  "academic-records": "Certificate of Registration Records",
  "enrollment-documents": "Certificate of Registration Records",
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
  "grade-approvals": "Grade approvals",
  "academic-transcripts": "Academic transcripts",
  "enrollment-change-requests": "Add/Drop requests",
  "enrollment-requests": "Enrollment requests",
  "enrollment-approvals": "Enrollment approvals",
  "irregular-enrollments": "Irregular Student Advising & Approvals",
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
  "it-control-students": "IT Control student accounts",
  "it-control-faculty": "IT Control faculty accounts",
  graduates: "Graduates directory",
  "ched-reports": "CHED Reports",
  "professor-information": "My Information",
  students: "Students",
  "subject-withdrawals": "Subject withdrawals",
}

const unmigratedRegionNames: Partial<Record<string, string>> = {}

describe("connectedModuleRegistry", () => {
  beforeEach(() => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn<typeof fetch>()
        .mockResolvedValue(new Response(JSON.stringify({ data: [] }))),
    )
  })

  afterEach(() => vi.unstubAllGlobals())

  // Workspaces are lazy chunks (ADR 0029). Loading all 48 through Vite in a test
  // takes a while the first time, so warm them once up front and give this test
  // room; each render then resolves from the module cache.
  it(
    "dispatches every role-owned connected module ID",
    { timeout: 180_000 },
    async () => {
      await preloadConnectedModules()
      expect(Object.keys(connectedModuleRegistry).sort()).toEqual(
        [...connectedModuleIds].sort(),
      )

      for (const moduleId of connectedModuleIds) {
        const ModuleComponent = connectedModuleRegistry[moduleId]
        const role = (
          Object.keys(
            rolePortalDefinitions,
          ) as (keyof typeof rolePortalDefinitions)[]
        ).find((candidate) =>
          rolePortalDefinitions[candidate].modules.some(
            (module) => module.id === moduleId,
          ),
        )
        const view = renderWithSession(<ModuleComponent />, {
          session: {
            userId: "5",
            displayName: role === "dean" ? "Dean" : "Test User",
            role: role ?? "admission_staff",
            signedInAt: "2026-07-29T12:00:00Z",
          },
        })
        const expectedRegion =
          migratedRegionNames[moduleId] ??
          unmigratedRegionNames[moduleId] ??
          "Connected portal workspace"
        expect(
          await view.findByRole(
            "region",
            { name: expectedRegion },
            { timeout: 15_000 },
          ),
        ).toBeInTheDocument()
        view.unmount()
      }
    },
  )

  it.each([
    ["program_chair", "Students waiting for review"],
    ["registrar_head", "Students waiting for approval"],
    ["registrar_staff", "Transferee credits"],
  ] as const)(
    "gives the %s the credit mappings workspace built for that role",
    { timeout: 60_000 },
    async (role, heading) => {
      const ModuleComponent = connectedModuleRegistry["credit-mappings"]
      const view = renderWithSession(<ModuleComponent />, {
        session: {
          userId: "5",
          displayName: "Test User",
          role,
          signedInAt: "2026-07-29T12:00:00Z",
        },
      })

      expect(
        await view.findByRole(
          "heading",
          { name: heading },
          { timeout: 30_000 },
        ),
      ).toBeInTheDocument()
      view.unmount()
    },
  )

  it("connects curriculum approvals for Dean and Executive Director", async () => {
    expect(connectedModuleIds).toContain("curriculum-approvals")
    expect(connectedModuleRegistry["curriculum-approvals"]).toBeDefined()

    for (const role of ["dean", "executive_director"] as const) {
      expect(rolePortalDefinitions[role].modules).toContainEqual(
        expect.objectContaining({
          id: "curriculum-approvals",
          label: "Curriculum Approvals",
        }),
      )
    }

    const ModuleComponent = connectedModuleRegistry["curriculum-approvals"]
    const view = renderWithSession(<ModuleComponent />, {
      session: {
        userId: "5",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    expect(
      await view.findByRole("region", { name: "Curriculum Approvals" }),
    ).toBeInTheDocument()
    view.unmount()
  })

  it("shows a loading skeleton, announced once, while a workspace chunk loads", () => {
    const ModuleComponent = connectedModuleRegistry["curriculum-approvals"]
    const view = renderWithSession(<ModuleComponent />, {
      session: {
        userId: "5",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    // First paint is the fallback; the workspace arrives asynchronously.
    expect(
      view.container.querySelectorAll('[data-slot="skeleton"]').length,
    ).toBeGreaterThan(0)
    view.unmount()
  })

  it("does not connect any module outside the registry", () => {
    const plannedModuleIds = [...knownPortalModuleIds].filter(
      (moduleId) => !connectedModuleIds.includes(moduleId as never),
    )

    expect(plannedModuleIds.length).toBeGreaterThan(0)
    for (const moduleId of plannedModuleIds) {
      expect(connectedModuleRegistry[moduleId as never]).toBeUndefined()
    }
  })

  it("keeps the registry independent from role authorization", () => {
    expect(getRoleModule("student", "audit-logs")).toBeNull()
    expect(rolePortalDefinitions.registrar_head.modules).toContainEqual(
      expect.objectContaining({ id: "audit-logs" }),
    )
  })
})
