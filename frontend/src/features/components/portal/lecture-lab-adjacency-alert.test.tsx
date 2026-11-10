import { screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { LectureLabAdjacencyAlert } from "@/features/components/portal/lecture-lab-adjacency-alert"
import { renderWithSession } from "@/tests/render-app"

const programHeadSession = {
  userId: "7",
  displayName: "Program Head",
  role: "program_chair",
  college: "ccs",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

function violation(index: number) {
  return {
    section_code: `A${index}`,
    first: { section_id: index * 2 + 1, subject_code: "IT101" },
    second: { section_id: index * 2 + 2, subject_code: "IT101L" },
    reason: "not_back_to_back",
    days: ["Mon", "Wed"],
    message: `IT101 and IT101L (section A${index}) are not back-to-back on Mon, Wed.`,
  }
}

describe("LectureLabAdjacencyAlert", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it("warns about a pair that is not back-to-back, in the Program Head's words", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: [violation(1)] })),
    )
    renderWithSession(<LectureLabAdjacencyAlert termId={5} />, {
      session: programHeadSession,
    })

    expect(
      await screen.findByText(
        "1 lecture and laboratory pair is not back-to-back",
      ),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/IT101 and IT101L \(section A1\) are not back-to-back/),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/does not stop you from saving or submitting/),
    ).toBeInTheDocument()
    const requested = fetchMock.mock.calls[0]?.[0]
    expect(
      typeof requested === "string" ? requested : (requested as Request).url,
    ).toContain("/api/v1/academic-terms/5/lecture-lab-adjacency")
  })

  it("lists five and counts the rest", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [1, 2, 3, 4, 5, 6, 7].map((index) => violation(index)),
        }),
      ),
    )
    renderWithSession(<LectureLabAdjacencyAlert termId={5} />, {
      session: programHeadSession,
    })

    expect(
      await screen.findByText(
        "7 lecture and laboratory pairs are not back-to-back",
      ),
    ).toBeInTheDocument()
    expect(screen.getAllByRole("listitem")).toHaveLength(5)
    expect(screen.getByText("…and 2 more.")).toBeInTheDocument()
  })

  it("says nothing when every pair is back-to-back", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [] })))
    const { container } = renderWithSession(
      <LectureLabAdjacencyAlert termId={5} />,
      { session: programHeadSession },
    )

    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })

  it("stays quiet and does not block anything when the check fails", async () => {
    fetchMock.mockResolvedValue(new Response("{}", { status: 403 }))
    const { container } = renderWithSession(
      <LectureLabAdjacencyAlert termId={5} />,
      { session: programHeadSession },
    )

    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })

  it("does not ask the server without a term", () => {
    renderWithSession(<LectureLabAdjacencyAlert termId={null} />, {
      session: programHeadSession,
    })

    expect(fetchMock).not.toHaveBeenCalled()
  })
})
