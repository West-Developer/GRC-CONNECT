import { describe, expect, it } from "vitest"

import {
  enrollmentDeadlineNotice,
  isPathOpenWhileRestricted,
  isPortalRestricted,
  RESTRICTED_STUDENT_MODULE_ID,
  RESTRICTED_STUDENT_MODULE_IDS,
} from "@/features/lib/enrollment-deadline"

const DAY = 24 * 60 * 60 * 1000

describe("isPortalRestricted", () => {
  it("is true only while the profile carries an enrollment deadline", () => {
    expect(
      isPortalRestricted({ enrollment_deadline_at: "2026-10-21T10:00:00Z" }),
    ).toBe(true)
    expect(isPortalRestricted({ enrollment_deadline_at: null })).toBe(false)
  })

  it("is false when the profile is not known (loading or failed)", () => {
    expect(isPortalRestricted(undefined)).toBe(false)
    expect(isPortalRestricted(null)).toBe(false)
  })

  it("lands a restricted student on Enrollment and lets them open Enrollment and Grades only (ADR 0050)", () => {
    expect(RESTRICTED_STUDENT_MODULE_ID).toBe("enrollment")
    expect(RESTRICTED_STUDENT_MODULE_IDS).toEqual(["enrollment", "grades"])
  })
})

describe("isPathOpenWhileRestricted", () => {
  it("is true for Enrollment and Grades", () => {
    expect(isPathOpenWhileRestricted("/portal/enrollment")).toBe(true)
    expect(isPathOpenWhileRestricted("/portal/grades")).toBe(true)
  })

  it("is false for every other page, the GRC Connect home included", () => {
    expect(isPathOpenWhileRestricted("/portal")).toBe(false)
    expect(isPathOpenWhileRestricted("/portal/schedule")).toBe(false)
    expect(isPathOpenWhileRestricted("/portal/statement-of-account")).toBe(
      false,
    )
    expect(isPathOpenWhileRestricted("/portal/gradesheet")).toBe(false)
    expect(isPathOpenWhileRestricted("/login")).toBe(false)
  })
})

describe("enrollmentDeadlineNotice", () => {
  const now = new Date("2026-10-14T10:00:00Z")

  it("counts the days left, rounding a partial day up", () => {
    const sevenDays = new Date(now.getTime() + 7 * DAY).toISOString()
    const twoAndAHalf = new Date(now.getTime() + 2.5 * DAY).toISOString()

    expect(enrollmentDeadlineNotice(sevenDays, now).daysLeft).toBe(7)
    expect(enrollmentDeadlineNotice(twoAndAHalf, now).daysLeft).toBe(3)
  })

  it("says 1 day for the last day and never goes below it while time remains", () => {
    const tenMinutes = new Date(now.getTime() + 10 * 60 * 1000).toISOString()

    const notice = enrollmentDeadlineNotice(tenMinutes, now)

    expect(notice.daysLeft).toBe(1)
    expect(notice.countdown).toBe("in 1 day")
  })

  it("words the countdown in the plural and gives the date", () => {
    const notice = enrollmentDeadlineNotice("2026-10-21T10:00:00Z", now)

    expect(notice.countdown).toBe("in 7 days")
    expect(notice.dateLabel).toBe("October 21, 2026")
  })

  it("says the time ran out once the deadline has passed", () => {
    const notice = enrollmentDeadlineNotice("2026-10-14T09:59:00Z", now)

    expect(notice.daysLeft).toBe(0)
    expect(notice.countdown).toBe("today")
  })
})
