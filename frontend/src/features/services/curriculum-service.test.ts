import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  addCurriculumSubjectPlacement,
  createCurriculum,
  getCurrentCurriculumSubjects,
  replaceCurriculum,
  toCurriculumReplacement,
  transitionCurriculum,
} from "@/features/services/curriculum-service"

const replacement = {
  name: "BSCS 2026",
  subjects: [
    {
      subject_id: 11,
      year_level: 1,
      semester: "1st",
      is_required: true,
      prerequisites: [{ prerequisite_subject_id: 4, minimum_grade: "75" }],
    },
  ],
}

const response = {
  data: {
    type: "curriculum",
    id: 9,
    program_id: 1,
    name: "BSCS 2026",
    effective_school_year: "2026-2027",
    status: "draft",
    status_label: "Draft",
    decided_at: null,
    last_decision_reason: null,
    subjects: [
      {
        subject_id: 11,
        code: "CS101",
        title: "Programming 1",
        year_level: 1,
        semester: "1st",
        is_required: true,
        prerequisites: [
          { prerequisite_subject_id: 4, code: "MATH1", minimum_grade: "75" },
        ],
      },
    ],
  },
} as const

const currentSubjectsResponse = {
  data: [
    {
      type: "subject",
      id: 12,
      code: "CS102",
      title: "Data Structures",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
  ],
} as const

describe("curriculum-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("sends create and replacement payloads without server-owned fields", async () => {
    fetchMock
      .mockResolvedValueOnce(
        new Response(JSON.stringify(response), { status: 201 }),
      )
      .mockResolvedValueOnce(new Response(JSON.stringify(response)))

    await createCurriculum({
      program_id: 1,
      equivalency_source_curriculum_id: 2,
      ...replacement,
    })
    await replaceCurriculum(9, replacement)

    const createRequest = fetchMock.mock.calls[0]?.[1]
    const replaceRequest = fetchMock.mock.calls[1]?.[1]
    if (!createRequest || !replaceRequest) {
      throw new Error("Expected create and replacement requests.")
    }
    expect(createRequest.method).toBe("POST")
    expect(JSON.parse(createRequest.body as string)).toEqual({
      program_id: 1,
      equivalency_source_curriculum_id: 2,
      ...replacement,
    })
    expect(replaceRequest.method).toBe("PATCH")
    expect(JSON.parse(replaceRequest.body as string)).toEqual(replacement)
  })

  it("preserves every placement and prerequisite edge when metadata is edited", () => {
    expect(
      toCurriculumReplacement({
        ...response.data,
        name: "BSCS 2027",
      }),
    ).toEqual({
      name: "BSCS 2027",
      subjects: replacement.subjects,
    })
  })

  it("rejects a curriculum response with undeclared envelope fields", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ ...response, extra: "not-contractual" }), {
        status: 201,
      }),
    )

    await expect(
      createCurriculum({
        program_id: 1,
        equivalency_source_curriculum_id: 2,
        ...replacement,
      }),
    ).rejects.toMatchObject({ kind: "contract" })
  })

  it("sends a curriculum transition request and returns the updated curriculum", async () => {
    const submittedResponse = {
      data: {
        ...response.data,
        status: "pending_dean_review",
        status_label: "Pending Dean Review",
        decided_at: "2026-08-07T00:00:00.000000Z",
      },
    } as const
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify(submittedResponse)),
    )

    const updated = await transitionCurriculum(9, { action: "submit" })

    const transitionRequest = fetchMock.mock.calls[0]?.[1]
    if (!transitionRequest) {
      throw new Error("Expected a transition request.")
    }
    expect(transitionRequest.method).toBe("PATCH")
    expect(JSON.parse(transitionRequest.body as string)).toEqual({
      action: "submit",
    })
    expect(updated.status).toBe("pending_dean_review")
  })

  it("sends a reason when the transition action requires one", async () => {
    fetchMock.mockResolvedValueOnce(new Response(JSON.stringify(response)))

    await transitionCurriculum(9, {
      action: "dean_return",
      reason: "Missing PATHFIT 2.",
    })

    const transitionRequest = fetchMock.mock.calls[0]?.[1]
    if (!transitionRequest) {
      throw new Error("Expected a transition request.")
    }
    expect(JSON.parse(transitionRequest.body as string)).toEqual({
      action: "dean_return",
      reason: "Missing PATHFIT 2.",
    })
  })

  it("gets current-program subjects from the reviewed source endpoint", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify(currentSubjectsResponse)),
    )

    await expect(getCurrentCurriculumSubjects(4)).resolves.toEqual(
      currentSubjectsResponse.data,
    )

    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "/api/v1/programs/4/current-curriculum-subjects",
    )
    expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({ method: "GET" })
  })

  it("posts a discriminated subject-placement request and parses its curriculum", async () => {
    fetchMock.mockResolvedValueOnce(new Response(JSON.stringify(response)))

    await expect(
      addCurriculumSubjectPlacement(9, {
        source: "new",
        code: "CS102",
        title: "Data Structures",
        units: 3,
        year_level: 1,
        semester: "2nd",
      }),
    ).resolves.toEqual(response.data)

    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "/api/v1/curricula/9/subject-placements",
    )
    expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({ method: "POST" })
    expect(JSON.parse(fetchMock.mock.calls[0]?.[1]?.body as string)).toEqual({
      source: "new",
      code: "CS102",
      title: "Data Structures",
      units: 3,
      year_level: 1,
      semester: "2nd",
    })
  })

  it("surfaces a contract error when the source envelope has undeclared fields", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({ ...currentSubjectsResponse, extra: "unexpected" }),
      ),
    )

    await expect(getCurrentCurriculumSubjects(4)).rejects.toMatchObject({
      kind: "contract",
    })
  })

  it("surfaces a contract error when the placement response has undeclared fields", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: { ...response.data, unexpected: "not-contractual" },
        }),
      ),
    )

    await expect(
      addCurriculumSubjectPlacement(9, {
        source: "existing",
        subject_id: 12,
        year_level: 1,
        semester: "1st",
      }),
    ).rejects.toMatchObject({ kind: "contract" })
  })
})
