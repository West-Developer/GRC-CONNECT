"use client"

import { useEffect, useState } from "react"

import { sectionAnchor } from "@/features/components/guide/guide-chapter"
import { useMediaQuery } from "@/features/hooks/use-media-query"
import {
  guideRoleLabel,
  type GuideChapter,
  type GuideSelection,
} from "@/features/lib/user-guide"

const WIDE_QUERY = "(min-width: 64rem)"

/** The id of the topic nearest the top of the screen, as the reader scrolls. */
function useActiveAnchor(ids: readonly string[]) {
  const [active, setActive] = useState<string | null>(null)
  const key = ids.join("|")

  useEffect(() => {
    if (typeof IntersectionObserver === "undefined") return

    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)
        if (visible[0]) {
          setActive(visible[0].target.id)
        }
      },
      { rootMargin: "-12% 0px -75% 0px" },
    )

    for (const id of key.split("|")) {
      const element = document.getElementById(id)
      if (element) observer.observe(element)
    }

    return () => observer.disconnect()
  }, [key])

  return active
}

/**
 * "On this page": the topics of the chapters on screen, with the one being read
 * marked. A single chosen role lists every topic; the all-roles view lists
 * chapters only so the list stays short.
 */
export function GuideRail({
  chapters,
  selection,
}: {
  chapters: readonly GuideChapter[]
  selection: GuideSelection
}) {
  const wide = useMediaQuery(WIDE_QUERY)
  const topicLevel = selection !== "all"

  const ids = chapters.flatMap((chapter) =>
    topicLevel
      ? chapter.sections.map((section) => sectionAnchor(chapter.id, section.id))
      : [chapter.id],
  )
  const active = useActiveAnchor(ids)

  return (
    <details className="guide-rail" open={wide || undefined}>
      <summary>On this page</summary>
      <nav aria-label="On this page">
        {chapters.map((chapter) => (
          <div key={chapter.id} className="guide-rail__group">
            <a
              className="guide-rail__chapter"
              href={`#${chapter.id}`}
              aria-current={active === chapter.id ? "location" : undefined}
            >
              {guideRoleLabel(chapter.id)}
            </a>
            {topicLevel ? (
              <ol>
                {chapter.sections.map((section) => {
                  const anchor = sectionAnchor(chapter.id, section.id)
                  return (
                    <li key={section.id}>
                      <a
                        href={`#${anchor}`}
                        aria-current={
                          active === anchor ? "location" : undefined
                        }
                      >
                        {section.title}
                      </a>
                    </li>
                  )
                })}
              </ol>
            ) : null}
          </div>
        ))}
      </nav>
    </details>
  )
}
