import { describe, expect, it } from "vitest"

import { userRoles } from "@/features/auth/roles"
import {
  getRoleModule,
  rolePortalDefinitions,
} from "@/features/portal/role-capabilities"

const expectedModuleIds = {
  student: [
    "enrollment",
    "schedule",
    "statement-of-account",
    "grades",
    "digital-com",
    "student-information",
    "admission-requirements",
  ],
  admission_staff: ["student-records", "enrollment-dashboard"],
  faculty: [
    "availability-preferences",
    "teaching-schedule",
    "grade-submission",
    "professor-information",
  ],
  // Irregular Advising and the Enrollment Dashboard sit directly under
  // Enrollment (stakeholder Doc 12); Analytics stays with the dashboard.
  program_chair: [
    "program-chair-enrollment",
    "irregular-enrollments",
    "enrollment-dashboard",
    "program-chair-analytics",
    "subjects-prerequisites",
    "schedule",
    "faculty-loading",
    "faculty-workforce",
    "rooms",
    "credit-mappings",
    "faculty-invitations",
  ],
  dean: [
    "schedule-approvals",
    "curriculum-approvals",
    "enrollment-dashboard",
    "faculty-load-monitoring",
    "honors",
    "reports",
  ],
  executive_director: [
    "master-schedule",
    "curriculum-approvals",
    "institution-dashboard",
    "enrollment-dashboard",
    "kpis",
    "reports",
  ],
  registrar_head: [
    "academic-terms",
    "enrollment-approvals",
    "students",
    "credit-mappings",
    "submitted-schedules",
    "section-change-requests",
    "grade-approvals",
    "academic-transcripts",
    "subject-withdrawals",
    "graduates",
    "cor-records",
    "enrollment-requests",
    "attrition-analytics",
    "registrar-analytics",
    "ched-reports",
    "enrollment-dashboard",
    "payment-discount",
    "audit-logs",
    "rooms",
    "faculty-workforce",
    "staff-invitations",
  ],
  registrar_staff: [
    "enrollment-approvals",
    "students",
    "credit-mappings",
    "enrollment-requests",
    "cor-records",
    "graduates",
    "enrollment-dashboard",
  ],
  accounting_staff: [
    "payment-queue",
    "payment-records",
    "advance-payment",
    "statement-of-account",
    "cor-records",
    "queue-kiosk-access",
    "enrollment-dashboard",
    "fee-settings",
  ],
  it_admin: ["it-control-students", "it-control-faculty"],
  queue_kiosk: [],
  super_admin: ["super-admin-accounts", "audit-logs"],
} as const

describe("rolePortalDefinitions", () => {
  it("exposes the two IT control modules in order", () => {
    expect(
      rolePortalDefinitions.it_admin.modules.map((module) => module.id),
    ).toEqual(["it-control-students", "it-control-faculty"])
  })

  it("covers every demo role with the approved ordered module IDs", () => {
    expect(Object.keys(rolePortalDefinitions)).toEqual(userRoles)

    for (const role of userRoles) {
      expect(rolePortalDefinitions[role].modules.map(({ id }) => id)).toEqual(
        expectedModuleIds[role],
      )
    }
  })

  it("provides meaningful labels and descriptions without duplicate role modules", () => {
    for (const role of userRoles) {
      const definition = rolePortalDefinitions[role]
      const ids = definition.modules.map(({ id }) => id)

      expect(definition.roleLabel.trim()).not.toBe("")
      expect(definition.welcomeHeading.trim()).not.toBe("")
      expect(new Set(ids).size).toBe(ids.length)

      for (const module of definition.modules) {
        expect(module.label.trim()).not.toBe("")
        expect(module.description.trim()).not.toBe("")
      }
    }
  })
})

describe("getRoleModule", () => {
  it("names the Cashier payment-record destination Transaction History", () => {
    expect(getRoleModule("accounting_staff", "payment-records")?.label).toBe(
      "Transaction History",
    )
  })

  it("returns only modules assigned to the active role", () => {
    expect(getRoleModule("student", "enrollment")?.label).toBe("Enrollment")
    expect(getRoleModule("student", "payment-queue")).toBeNull()
    expect(getRoleModule("student", "unknown")).toBeNull()
    expect(getRoleModule("dean", "schedule-approvals")?.label).toBe(
      "Enrollment",
    )
    expect(getRoleModule("executive_director", "master-schedule")?.label).toBe(
      "Enrollment",
    )
    expect(
      getRoleModule("program_chair", "subjects-prerequisites")?.label,
    ).toBe("Curriculum Editor")
  })
})
