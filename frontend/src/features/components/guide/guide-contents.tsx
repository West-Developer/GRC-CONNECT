import Link from "next/link"

import { sectionAnchor } from "@/features/components/guide/guide-chapter"
import {
  guideRoleChapters,
  guideRoles,
  guideRoleLabel,
  guideStartChapter,
  type GuideChapter,
  type GuideSelection,
} from "@/features/lib/user-guide"

function topicCount(chapter: GuideChapter) {
  const count = chapter.sections.length
  return `${count} ${count === 1 ? "topic" : "topics"}`
}

function ContentsRow({
  chapter,
  href,
  current,
  expanded,
  included,
}: {
  chapter: GuideChapter
  href: string
  current: boolean
  expanded: boolean
  /** Whether the chapter is part of the document being read or printed. */
  included: boolean
}) {
  return (
    <li
      className="guide-contents__row"
      data-current={current ? "true" : undefined}
      data-expanded={expanded ? "true" : undefined}
      data-included={included ? "true" : undefined}
    >
      <Link href={href} aria-current={current ? "true" : undefined}>
        <span className="guide-contents__title">
          {guideRoleLabel(chapter.id)}
        </span>
        <span className="guide-contents__tagline">{chapter.tagline}</span>
        <span className="guide-contents__count">{topicCount(chapter)}</span>
      </Link>
      <ol className="guide-contents__topics">
        {chapter.sections.map((section) => (
          <li key={section.id}>
            <a href={`#${sectionAnchor(chapter.id, section.id)}`}>
              {section.title}
            </a>
          </li>
        ))}
      </ol>
    </li>
  )
}

/**
 * The guide's table of contents and its role chooser in one list, like the
 * contents page of a printed handbook. On screen only the chosen role opens
 * its topics; in the PDF every chapter the document contains does.
 */
export function GuideContents({ selection }: { selection: GuideSelection }) {
  return (
    <nav className="guide-contents" aria-labelledby="guide-contents-title">
      <h2 id="guide-contents-title">
        <span className="guide-screen-only">Choose your role</span>
        <span className="guide-print-only">Contents</span>
      </h2>
      <p className="guide-contents__hint">
        Pick the role you sign in with. You will see the pages that role uses
        and how to do each task.
      </p>

      <h3 className="guide-contents__group">For everyone</h3>
      <ol className="guide-contents__list">
        <ContentsRow
          chapter={guideStartChapter}
          href="#start"
          current={false}
          expanded={selection !== null}
          included
        />
      </ol>

      <h3 className="guide-contents__group" data-role-group>
        By role
      </h3>
      <ol className="guide-contents__list">
        {guideRoles.map((role) => (
          <ContentsRow
            key={role}
            chapter={guideRoleChapters[role]}
            href={`/user-guide?role=${role}#${role}`}
            current={selection === role}
            expanded={selection === role || selection === "all"}
            included={selection === role || selection === "all"}
          />
        ))}
      </ol>
    </nav>
  )
}
