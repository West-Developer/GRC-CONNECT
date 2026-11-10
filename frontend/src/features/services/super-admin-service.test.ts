import { describe, expect, it, vi } from "vitest"

import {
  clearActingContext,
  SUPER_ADMIN_ACTING_CONTEXT_PATH,
  switchActingContext,
} from "@/features/services/super-admin-service"
import * as apiClient from "@/features/services/api-client"
import { agreedPrivacyNotice } from "@/tests/privacy-notice-fixture"

describe("super-admin-service", () => {
  const validUserResponse = {
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
      acting_context: {
        role: "dean",
        college: "ccs",
      },
    },
  }

  it("calls putAuthenticatedJson to switch acting context and validates response", async () => {
    const putSpy = vi
      .spyOn(apiClient, "putAuthenticatedJson")
      .mockResolvedValueOnce(validUserResponse)

    const result = await switchActingContext({ role: "dean", college: "ccs" })

    expect(putSpy).toHaveBeenCalledWith(
      SUPER_ADMIN_ACTING_CONTEXT_PATH,
      { role: "dean", college: "ccs" },
      undefined,
    )
    expect(result.data.role).toBe("dean")
    expect(result.data.college).toBe("ccs")
  })

  it("calls deleteAuthenticatedJson to clear acting context and validates response", async () => {
    const consoleResponse = {
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
      },
    }

    const deleteSpy = vi
      .spyOn(apiClient, "deleteAuthenticatedJson")
      .mockResolvedValueOnce(consoleResponse)

    const result = await clearActingContext()

    expect(deleteSpy).toHaveBeenCalledWith(
      SUPER_ADMIN_ACTING_CONTEXT_PATH,
      undefined,
    )
    expect(result.data.role).toBe("super_admin")
  })

  it("throws contract error when response payload is malformed", async () => {
    vi.spyOn(apiClient, "putAuthenticatedJson").mockResolvedValueOnce({
      data: { invalid: true },
    })

    await expect(
      switchActingContext({ role: "dean", college: "ccs" }),
    ).rejects.toThrow(/contract/)
  })
})
