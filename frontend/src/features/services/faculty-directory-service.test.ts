import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { getFacultyMembers } from "@/features/services/faculty-directory-service"

describe("faculty-directory-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("parses the published active faculty directory without retaining email", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: [
            {
              type: "faculty_member",
              id: 12,
              name: "Prof. Reyes",
              college: "ccs",
              status: "active",
              status_label: "Active",
              employment_type: "full_time",
              employment_type_label: "Full-time",
              planning_unit_reference: 18,
              is_assignable: true,
            },
          ],
        }),
      ),
    )

    await expect(getFacultyMembers()).resolves.toEqual([
      {
        type: "faculty_member",
        id: 12,
        name: "Prof. Reyes",
        college: "ccs",
        status: "active",
        status_label: "Active",
        employment_type: "full_time",
        employment_type_label: "Full-time",
        planning_unit_reference: 18,
        is_assignable: true,
      },
    ])
  })

  it("rejects undeclared directory fields such as email", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: [
            {
              type: "faculty_member",
              id: 12,
              name: "Prof. Reyes",
              status: "active",
              status_label: "Active",
              email: "private@example.test",
            },
          ],
        }),
      ),
    )

    await expect(getFacultyMembers()).rejects.toMatchObject({
      kind: "contract",
    })
  })
})
