import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { getProgramChairReviewCounts } from "@/features/services/program-chair-review-counts-service"

describe("program-chair-review-counts-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it("reads how many irregular enrollments and credit mappings are waiting", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: {
            type: "program_chair_review_counts",
            irregular_advising: 3,
            credit_mappings: 5,
          },
        }),
      ),
    )

    await expect(getProgramChairReviewCounts()).resolves.toEqual({
      type: "program_chair_review_counts",
      irregular_advising: 3,
      credit_mappings: 5,
    })
    const input = fetchMock.mock.calls[0]?.[0]
    expect(String(input instanceof Request ? input.url : input)).toContain(
      "/api/v1/program-chair/review-counts",
    )
  })

  it("rejects a response that does not match the contract", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: {
            type: "program_chair_review_counts",
            irregular_advising: -1,
            credit_mappings: 0,
          },
        }),
      ),
    )

    await expect(getProgramChairReviewCounts()).rejects.toThrow(/contract/)
  })
})
