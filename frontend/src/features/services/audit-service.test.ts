import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { auditLogFiltersSchema } from "@/features/schemas/audit-schema"
import { getAuditLogs } from "@/features/services/audit-service"

describe("getAuditLogs", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("serializes every published audit filter and parses a paginated result", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [],
          links: {
            first: "https://api.test/audit-logs?page=1",
            last: "https://api.test/audit-logs?page=1",
            prev: null,
            next: null,
          },
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
        }),
      ),
    )

    await expect(
      getAuditLogs({
        action: "section.updated",
        auditable_type: "section",
        actor_user_id: 7,
        from: "2026-07-01",
        to: "2026-07-31",
        page: 2,
        per_page: 20,
      }),
    ).resolves.toMatchObject({ meta: { current_page: 1, per_page: 20 } })

    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining(
        "action=section.updated&auditable_type=section&actor_user_id=7&from=2026-07-01&to=2026-07-31&page=2&per_page=20",
      ),
      expect.anything(),
    )
  })

  it("rejects actions and entity types outside the backend audit vocabulary", () => {
    expect(
      auditLogFiltersSchema.safeParse({ action: "invented.action" }).success,
    ).toBe(false)
    expect(
      auditLogFiltersSchema.safeParse({ auditable_type: "invented_type" })
        .success,
    ).toBe(false)
  })

  it("surfaces a forbidden audit response without accepting any audit data", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            code: "FORBIDDEN",
            message: "Forbidden",
            errors: {},
            request_id: "req-forbidden",
          },
        }),
        { status: 403 },
      ),
    )

    await expect(getAuditLogs({ page: 1, per_page: 20 })).rejects.toMatchObject(
      { status: 403, code: "FORBIDDEN" },
    )
  })
})
