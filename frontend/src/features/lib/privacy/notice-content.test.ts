import { describe, expect, it } from "vitest"

import {
  AGREEMENT_TEXT,
  agreementTextForRole,
  DATA_USES,
  INLINE_NOTICES,
  PRIVACY_LAW,
  PRIVACY_NOTICE_CONTENT_VERSION,
  RETENTION,
  RIGHTS,
  SECURITY,
  STAFF_DUTIES,
  WHO_IS_COVERED,
} from "@/features/lib/privacy/notice-content"

describe("the Data Privacy Notice content", () => {
  it("is stamped with a version the server can be matched against", () => {
    expect(PRIVACY_NOTICE_CONTENT_VERSION).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it("names the law and the regulator", () => {
    expect(PRIVACY_LAW.name).toContain("Republic Act No. 10173")
    expect(PRIVACY_LAW.regulator).toBe("National Privacy Commission")
  })

  it("covers every kind of person whose data the system holds", () => {
    const ids = WHO_IS_COVERED.map((group) => group.id)

    expect(ids).toEqual(
      expect.arrayContaining([
        "applicants",
        "students",
        "former-students",
        "faculty",
        "staff",
        "queue",
        "others",
      ]),
    )
    expect(new Set(ids).size).toBe(ids.length)
  })

  it("says for every kind of data what is kept, why, and who sees it", () => {
    const ids = DATA_USES.map((use) => use.id)

    expect(new Set(ids).size).toBe(ids.length)
    for (const use of DATA_USES) {
      expect(use.label, use.id).not.toBe("")
      expect(use.data.length, `${use.id} data`).toBeGreaterThan(0)
      expect(use.purposes.length, `${use.id} purposes`).toBeGreaterThan(0)
      expect(use.seenBy.length, `${use.id} seen by`).toBeGreaterThan(0)
    }
  })

  it("marks the data the law treats as sensitive (age and education records)", () => {
    const sensitive = DATA_USES.filter((use) => use.sensitive).map(
      (use) => use.id,
    )

    expect(sensitive).toEqual(
      expect.arrayContaining(["identity", "academic", "documents"]),
    )
  })

  it("gives every short notice beside a form what it asks for, why and who sees it", () => {
    const notices = Object.entries(INLINE_NOTICES)

    expect(notices.map(([id]) => id).sort()).toEqual(
      [
        "account-setup-faculty",
        "account-setup-staff",
        "account-setup-student",
        "faculty-availability",
        "forgot-password",
        "invite-faculty",
        "invite-staff",
        "profile-change",
        "sign-in",
        "tor-upload",
        "withdrawal",
      ].sort(),
    )
    for (const [id, notice] of notices) {
      expect(notice.collects, `${id} collects`).not.toBe("")
      expect(notice.purpose, `${id} purpose`).not.toBe("")
      expect(notice.seenBy, `${id} seen by`).not.toBe("")
    }
  })

  it("states no retention period the school has not published", () => {
    // The school's records-retention schedule is its own decision; the notice must not invent one.
    for (const line of RETENTION) {
      expect(line).not.toMatch(/\d/)
    }
    expect(RETENTION.join(" ")).toContain("records-retention schedule")
  })

  it("lists the rights of the person, including a complaint to the regulator", () => {
    const ids = RIGHTS.map((right) => right.id)

    expect(ids).toEqual(
      expect.arrayContaining([
        "informed",
        "object",
        "access",
        "correct",
        "erase",
        "portability",
        "complain",
      ]),
    )
    expect(RIGHTS.find((right) => right.id === "complain")?.body).toContain(
      "National Privacy Commission",
    )
  })

  it("tells staff to keep what they handle confidential", () => {
    expect(STAFF_DUTIES.join(" ")).toContain(
      "only for the work you are assigned",
    )
    expect(AGREEMENT_TEXT.staff).toContain("confidential")
    expect(SECURITY.join(" ")).toContain("National Privacy Commission")
  })

  it("asks a student and a staff member to agree to different words", () => {
    expect(agreementTextForRole("student")).toBe(AGREEMENT_TEXT.student)
    expect(agreementTextForRole("admission_staff")).toBe(AGREEMENT_TEXT.staff)
    expect(agreementTextForRole("faculty")).toBe(AGREEMENT_TEXT.staff)
    expect(agreementTextForRole(undefined)).toBe(AGREEMENT_TEXT.staff)
    expect(AGREEMENT_TEXT.student).toContain("sensitive personal information")
  })
})
