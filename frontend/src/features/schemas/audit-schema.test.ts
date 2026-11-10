import { describe, expect, it } from "vitest"

import {
  auditActionSchema,
  auditLogFiltersSchema,
  auditLogSchema,
  auditableTypeSchema,
} from "@/features/schemas/audit-schema"

describe("queue kiosk audit vocabulary", () => {
  it.each(["queue_kiosk_credential.viewed", "queue_kiosk.password_changed"])(
    "accepts %s as a filterable action",
    (action) => {
      expect(auditActionSchema.parse(action)).toBe(action)
      expect(auditLogFiltersSchema.parse({ action }).action).toBe(action)
    },
  )

  it("accepts queue kiosk credentials as a filterable resource type", () => {
    expect(auditableTypeSchema.parse("queue_kiosk_credential")).toBe(
      "queue_kiosk_credential",
    )
    expect(
      auditLogFiltersSchema.parse({ auditable_type: "queue_kiosk_credential" })
        .auditable_type,
    ).toBe("queue_kiosk_credential")
  })

  // Regression: bc11ef1 added `acting_role`, `acting_role_label` and `acting_college` to every
  // audit_log resource, but this strict schema did not know them, so every opened person on the
  // Audit Logs screen failed with "Unexpected API response".
  const apiAuditLog = {
    type: "audit_log",
    id: 7,
    actor_user_id: 3,
    actor_name: "Queue Kiosk",
    actor_role: "queue_kiosk",
    actor_role_label: "Queue Kiosk",
    acting_role: null,
    acting_role_label: null,
    acting_college: null,
    action: "queue_ticket.served",
    auditable_type: "queue_ticket",
    auditable_id: 5,
    before_values: null,
    after_values: null,
    changes: [],
    reason: null,
    request_id: "request-7",
    ip_address: null,
    created_at: "2026-10-03T04:09:00Z",
  }

  it("accepts the acting-context fields the API sends on every audit record", () => {
    const parsed = auditLogSchema.parse(apiAuditLog)
    expect(parsed.acting_role).toBeNull()
    expect(parsed.acting_college).toBeNull()
  })

  it("accepts a Super Admin record that names the role and college acted as", () => {
    const parsed = auditLogSchema.parse({
      ...apiAuditLog,
      actor_role: "super_admin",
      actor_role_label: "Super Admin",
      acting_role: "dean",
      acting_role_label: "Dean",
      acting_college: "ccs",
    })
    expect(parsed.acting_role_label).toBe("Dean")
    expect(parsed.acting_college).toBe("ccs")
  })

  it("continues accepting new non-filterable action strings in audit resources", () => {
    expect(
      auditLogSchema.parse({
        type: "audit_log",
        id: 1,
        actor_user_id: 1,
        actor_name: "Accounting Person",
        actor_role: "accounting_staff",
        actor_role_label: "Accounting Staff",
        action: "future.action",
        auditable_type: "future_resource",
        auditable_id: 1,
        before_values: null,
        after_values: null,
        changes: [],
        reason: null,
        request_id: "request-1",
        ip_address: null,
        created_at: "2026-08-23T00:00:00Z",
      }).action,
    ).toBe("future.action")
  })
})
