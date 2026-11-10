import fs from "node:fs"
import path from "node:path"

import { describe, expect, it } from "vitest"

import {
  chaptersForSelection,
  guideAllChapters,
  guidePdfPath,
  guideRoleChapters,
  guideRoleLabel,
  guideRoles,
  guideStartChapter,
  parseGuideSelection,
} from "@/features/lib/user-guide"
import { userGuideShots } from "@/features/lib/user-guide/shots"
import type { GuideBlock } from "@/features/lib/user-guide/types"
import { rolePortalDefinitions } from "@/features/portal/role-capabilities"

function* blocksOf() {
  for (const chapter of guideAllChapters) {
    for (const section of chapter.sections) {
      for (const block of section.blocks) {
        yield { chapter, section, block }
      }
    }
  }
}

function shotIdsOf(block: GuideBlock): readonly string[] {
  if (block.kind === "figure") return [block.shot]
  if (block.kind === "phones") return block.shots
  return []
}

describe("user guide content", () => {
  it("covers exactly the nine institutional roles, never IT Control or Super Admin", () => {
    expect([...guideRoles].sort()).toEqual(
      [
        "student",
        "admission_staff",
        "faculty",
        "program_chair",
        "dean",
        "executive_director",
        "registrar_head",
        "registrar_staff",
        "accounting_staff",
      ].sort(),
    )
    expect(Object.keys(guideRoleChapters)).not.toContain("it_admin")
    expect(Object.keys(guideRoleChapters)).not.toContain("super_admin")
    expect(Object.keys(guideRoleChapters)).not.toContain("queue_kiosk")

    const text = JSON.stringify(guideAllChapters)
    expect(text).not.toMatch(/super[ _-]?admin/i)
    expect(text).not.toMatch(/it[ _-]?control/i)
    expect(text).not.toMatch(/it_admin/i)
  })

  it("gives every chapter a unique id, matching the role it documents", () => {
    const ids = guideAllChapters.map((chapter) => chapter.id)
    expect(new Set(ids).size).toBe(ids.length)
    for (const role of guideRoles) {
      expect(guideRoleChapters[role].id).toBe(role)
      expect(guideRoleLabel(role)).toBe(rolePortalDefinitions[role].roleLabel)
    }
    expect(guideStartChapter.id).toBe("start")
  })

  it("keeps section ids unique inside a chapter and writes real content", () => {
    for (const chapter of guideAllChapters) {
      const ids = chapter.sections.map((section) => section.id)
      expect(new Set(ids).size, chapter.id).toBe(ids.length)
      expect(chapter.intro.length, chapter.id).toBeGreaterThan(0)
      expect(chapter.sections.length, chapter.id).toBeGreaterThan(2)
      for (const section of chapter.sections) {
        expect(
          section.blocks.length,
          `${chapter.id}/${section.id}`,
        ).toBeGreaterThan(0)
      }
    }
  })

  it("only refers to screenshots that exist, as data and as a file", () => {
    const missing: string[] = []
    for (const { chapter, section, block } of blocksOf()) {
      for (const id of shotIdsOf(block)) {
        const shot = userGuideShots[id]
        if (!shot) {
          missing.push(`${chapter.id}/${section.id}: no entry for "${id}"`)
          continue
        }
        const file = path.join(process.cwd(), "public", shot.src)
        if (!fs.existsSync(file)) {
          missing.push(`${chapter.id}/${section.id}: no file ${shot.src}`)
        }
      }
    }
    expect(missing).toEqual([])
  })

  it("describes every screenshot for a screen reader and keeps markers inside the image", () => {
    for (const [id, shot] of Object.entries(userGuideShots)) {
      expect(shot.alt.length, id).toBeGreaterThan(15)
      expect(shot.width, id).toBeGreaterThan(0)
      expect(shot.height, id).toBeGreaterThan(0)
      for (const mark of shot.marks ?? []) {
        // A marker for something below the fold would be negative-sized or off the picture.
        expect(mark.w, `${id} #${mark.n}`).toBeGreaterThan(1)
        expect(mark.h, `${id} #${mark.n}`).toBeGreaterThan(1)
        expect(mark.x, `${id} #${mark.n}`).toBeGreaterThanOrEqual(0)
        expect(mark.y, `${id} #${mark.n}`).toBeGreaterThanOrEqual(0)
        expect(mark.x + mark.w, `${id} #${mark.n}`).toBeLessThanOrEqual(100.01)
        expect(mark.y + mark.h, `${id} #${mark.n}`).toBeLessThanOrEqual(100.01)
      }
    }
  })

  it("leaves no unused screenshot in the manifest", () => {
    const used = new Set<string>()
    for (const { block } of blocksOf()) {
      for (const id of shotIdsOf(block)) used.add(id)
    }
    // The hero picture is used by the page itself, not by a chapter.
    used.add("hero-portal")
    const unused = Object.keys(userGuideShots).filter((id) => !used.has(id))
    expect(unused).toEqual([])
  })
})

describe("user guide selection", () => {
  it("reads ?role= and ignores anything it does not know", () => {
    expect(parseGuideSelection("student")).toBe("student")
    expect(parseGuideSelection("registrar_head")).toBe("registrar_head")
    expect(parseGuideSelection("all")).toBe("all")
    expect(parseGuideSelection("super_admin")).toBeNull()
    expect(parseGuideSelection("it_admin")).toBeNull()
    expect(parseGuideSelection("")).toBeNull()
    expect(parseGuideSelection(null)).toBeNull()
  })

  it("shows everyone's start chapter with the chosen role, or every chapter", () => {
    expect(chaptersForSelection(null).map((c) => c.id)).toEqual(["start"])
    expect(chaptersForSelection("dean").map((c) => c.id)).toEqual([
      "start",
      "dean",
    ])
    expect(chaptersForSelection("all")).toHaveLength(guideRoles.length + 1)
  })

  it("points each role at its own PDF and everyone else at the complete one", () => {
    expect(guidePdfPath(null)).toBe(
      "/downloads/grc-enrollment-system-user-manual-and-guide.pdf",
    )
    expect(guidePdfPath("all")).toBe(
      "/downloads/grc-enrollment-system-user-manual-and-guide.pdf",
    )
    expect(guidePdfPath("program_chair")).toBe(
      "/downloads/grc-enrollment-system-user-manual-and-guide-program-chair.pdf",
    )
  })
})
