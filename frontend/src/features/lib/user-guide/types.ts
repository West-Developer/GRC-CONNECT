import type { UserRole } from "@/features/auth/roles"

/**
 * The roles the User Manual and Guide covers: the nine institutional roles in
 * PRD §3. IT Control, the Super Admin console and the kiosk device identity are
 * deliberately not documented here (owner decision, 2026-10-16).
 */
export type GuideRole = Exclude<
  UserRole,
  "it_admin" | "queue_kiosk" | "super_admin"
>

/** A numbered marker drawn on a screenshot, in percent of the image size. */
export interface GuideMark {
  n: number
  x: number
  y: number
  w: number
  h: number
}

/** One captured screenshot. Written by `scripts/user-guide/capture.mjs`. */
export interface GuideShot {
  src: string
  width: number
  height: number
  alt: string
  marks?: readonly GuideMark[]
}

export interface GuideStep {
  /** Plain text with `**bold**` for on-screen labels and `code` spans. */
  text: string
  /** An optional second line that explains the step. */
  detail?: string
}

export interface GuideTerm {
  term: string
  meaning: string
}

export interface GuideJourneyStop {
  who: string
  what: string
}

export type GuideBlock =
  | { kind: "paragraph"; text: string }
  | { kind: "steps"; items: readonly GuideStep[] }
  | { kind: "bullets"; items: readonly string[] }
  /** A numbered key to the markers on the picture next to it. Not a sequence. */
  | { kind: "key"; items: readonly string[] }
  | {
      kind: "note"
      tone: "tip" | "careful" | "know"
      text: string
      title?: string
    }
  | { kind: "figure"; shot: string; caption: string }
  | { kind: "phones"; shots: readonly string[]; caption: string }
  | { kind: "terms"; items: readonly GuideTerm[] }
  | { kind: "journey"; items: readonly GuideJourneyStop[] }

export interface GuideSection {
  /** Unique within the chapter; the anchor is `${chapter.id}-${section.id}`. */
  id: string
  title: string
  blocks: readonly GuideBlock[]
}

export type GuideChapterId = "start" | GuideRole

export interface GuideChapter {
  id: GuideChapterId
  title: string
  /** One line on the contents page. */
  tagline: string
  /** Opening paragraph(s) shown under the chapter title. */
  intro: readonly string[]
  sections: readonly GuideSection[]
}
