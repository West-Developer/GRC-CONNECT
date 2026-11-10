import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  createFacultyAvailability,
  createFacultySubjectPreference,
  deleteFacultyAvailability,
  deleteFacultySubjectPreference,
  getFacultyAvailabilities,
  getFacultySubjectPreferences,
  getFacultyTeachingSchedule,
  updateFacultyAvailability,
  updateFacultySubjectPreference,
} from "@/features/services/faculty-service"

const availability = {
  type: "faculty_availability",
  id: 4,
  professor_id: 5,
  day_of_week: 1,
  starts_at_time: "08:00:00",
  ends_at_time: "10:00:00",
} as const

const preference = {
  type: "faculty_subject_preference",
  id: 6,
  professor_id: 5,
  academic_term_id: 1,
  subject_id: 101,
  rank: 1,
} as const

describe("faculty-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("uses the typed faculty availability contract for every mutation", async () => {
    fetchMock
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ data: [availability] })),
      )
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ data: availability }), { status: 201 }),
      )
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ data: availability })),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))

    await expect(getFacultyAvailabilities()).resolves.toEqual([availability])
    await expect(
      createFacultyAvailability({
        day_of_week: 1,
        starts_at_time: "08:00:00",
        ends_at_time: "10:00:00",
      }),
    ).resolves.toEqual(availability)
    await expect(
      updateFacultyAvailability(4, {
        day_of_week: 1,
        starts_at_time: "09:00:00",
        ends_at_time: "10:00:00",
      }),
    ).resolves.toEqual(availability)
    await expect(deleteFacultyAvailability(4)).resolves.toBeUndefined()

    expect(
      fetchMock.mock.calls.map(([url, request]) => [url, request?.method]),
    ).toEqual([
      ["http://127.0.0.1:8000/api/v1/faculty-availabilities", "GET"],
      ["http://127.0.0.1:8000/api/v1/faculty-availabilities", "POST"],
      ["http://127.0.0.1:8000/api/v1/faculty-availabilities/4", "PATCH"],
      ["http://127.0.0.1:8000/api/v1/faculty-availabilities/4", "DELETE"],
    ])
  })

  it("uses the typed faculty subject preference contract for every mutation", async () => {
    fetchMock
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ data: [preference] })),
      )
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ data: preference }), { status: 201 }),
      )
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: preference })))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))

    await expect(getFacultySubjectPreferences()).resolves.toEqual([preference])
    await expect(
      createFacultySubjectPreference({
        academic_term_id: 1,
        subject_id: 101,
        rank: 1,
      }),
    ).resolves.toEqual(preference)
    await expect(
      updateFacultySubjectPreference(6, {
        academic_term_id: 1,
        subject_id: 101,
        rank: 2,
      }),
    ).resolves.toEqual(preference)
    await expect(deleteFacultySubjectPreference(6)).resolves.toBeUndefined()
  })

  it("rejects Sunday availability before fetch", async () => {
    await expect(
      createFacultyAvailability({
        day_of_week: 7,
        starts_at_time: "08:00:00",
        ends_at_time: "09:00:00",
      }),
    ).rejects.toMatchObject({ kind: "contract" })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("rejects malformed availability times before fetch", async () => {
    await expect(
      createFacultyAvailability({
        day_of_week: 1,
        starts_at_time: "08:00",
        ends_at_time: "07:00:00",
      }),
    ).rejects.toMatchObject({ kind: "contract" })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("maps only assigned API-scoped sections into a teaching schedule", () => {
    const rows = getFacultyTeachingSchedule(
      [
        {
          type: "section",
          id: 44,
          academic_term_id: 1,
          subject_id: 101,
          section_code: "CS101-A",
          professor_id: 5,
          schedule_days: "MWF",
          starts_at_time: "08:00:00",
          ends_at_time: "09:30:00",
          room: "R201",
          capacity: 30,
          capacity_source: "plan",
          viability_threshold: null,
          enrolled_count: 0,
          remaining_seats: 30,
          is_block_exclusive: null,
          status: "published",
          status_label: "Published",
        },
      ],
      [
        {
          type: "subject",
          id: 101,
          code: "CS101",
          title: "Programming 1",
          units: 3,
          status: "active",
          status_label: "Active",
          is_completion_only: false,
        },
      ],
      [
        {
          type: "academic-term",
          id: 1,
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
    )

    expect(rows[0]).toMatchObject({
      sectionId: 44,
      subjectCode: "CS101",
      subjectTitle: "Programming 1",
      termLabel: "2026-2027 · 1st",
      days: "MWF",
      time: "8:00 AM–9:30 AM",
      room: "R201",
      statusLabel: "Published",
    })
  })
})
