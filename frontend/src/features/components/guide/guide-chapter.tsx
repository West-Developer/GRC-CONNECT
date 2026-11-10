import { Download } from "lucide-react"

import { GuideBlockView } from "@/features/components/guide/guide-blocks"
import { GuideText } from "@/features/components/guide/guide-text"
import { Button } from "@/features/components/ui/button"
import { guidePdfPath, guideRoleLabel } from "@/features/lib/user-guide"
import type { GuideChapter } from "@/features/lib/user-guide/types"

export function sectionAnchor(chapterId: string, sectionId: string) {
  return `${chapterId}-${sectionId}`
}

export function GuideChapterView({ chapter }: { chapter: GuideChapter }) {
  return (
    <article
      className="guide-chapter"
      id={chapter.id}
      aria-labelledby={`${chapter.id}-title`}
      data-chapter={chapter.id}
    >
      <header className="guide-chapter__header">
        <h2 id={`${chapter.id}-title`}>{chapter.title}</h2>
        {chapter.intro.map((paragraph) => (
          <p key={paragraph} className="guide-chapter__intro">
            <GuideText text={paragraph} />
          </p>
        ))}
        {chapter.id !== "start" ? (
          <Button
            asChild
            variant="outline"
            size="sm"
            className="guide-chapter__download"
          >
            <a href={guidePdfPath(chapter.id)} download>
              <Download data-icon="inline-start" aria-hidden="true" />
              Download the {guideRoleLabel(chapter.id)} PDF
            </a>
          </Button>
        ) : null}
      </header>

      {chapter.sections.map((section) => (
        <section
          key={section.id}
          className="guide-section"
          id={sectionAnchor(chapter.id, section.id)}
          aria-labelledby={`${sectionAnchor(chapter.id, section.id)}-title`}
        >
          <h3 id={`${sectionAnchor(chapter.id, section.id)}-title`}>
            {section.title}
          </h3>
          {section.blocks.map((block, index) => (
            <GuideBlockView key={index} block={block} />
          ))}
        </section>
      ))}
    </article>
  )
}
