import {
  GuideFigure,
  GuidePhones,
} from "@/features/components/guide/guide-figure"
import { GuideText } from "@/features/components/guide/guide-text"
import type { GuideBlock } from "@/features/lib/user-guide/types"

const NOTE_TITLES = {
  tip: "Tip",
  careful: "Be careful",
  know: "Good to know",
} as const

export function GuideBlockView({ block }: { block: GuideBlock }) {
  switch (block.kind) {
    case "paragraph":
      return (
        <p>
          <GuideText text={block.text} />
        </p>
      )
    case "bullets":
      return (
        <ul className="guide-bullets">
          {block.items.map((item) => (
            <li key={item}>
              <GuideText text={item} />
            </li>
          ))}
        </ul>
      )
    case "key":
      return (
        <ol className="guide-steps guide-key">
          {block.items.map((item) => (
            <li key={item}>
              <span className="guide-steps__text">
                <GuideText text={item} />
              </span>
            </li>
          ))}
        </ol>
      )
    case "steps":
      return (
        <ol className="guide-steps">
          {block.items.map((step) => (
            <li key={step.text}>
              <span className="guide-steps__text">
                <GuideText text={step.text} />
              </span>
              {step.detail ? (
                <span className="guide-steps__detail">
                  <GuideText text={step.detail} />
                </span>
              ) : null}
            </li>
          ))}
        </ol>
      )
    case "note":
      return (
        <div className="guide-note" data-tone={block.tone} role="note">
          <strong>{block.title ?? NOTE_TITLES[block.tone]}</strong>
          <p>
            <GuideText text={block.text} />
          </p>
        </div>
      )
    case "terms":
      return (
        <dl className="guide-terms">
          {block.items.map((item) => (
            <div key={item.term}>
              <dt>{item.term}</dt>
              <dd>
                <GuideText text={item.meaning} />
              </dd>
            </div>
          ))}
        </dl>
      )
    case "journey":
      return (
        <ol className="guide-journey">
          {block.items.map((stop) => (
            <li key={`${stop.who}-${stop.what}`}>
              <strong>{stop.who}</strong>
              <span>
                <GuideText text={stop.what} />
              </span>
            </li>
          ))}
        </ol>
      )
    case "figure":
      return <GuideFigure shotId={block.shot} caption={block.caption} />
    case "phones":
      return <GuidePhones shotIds={block.shots} caption={block.caption} />
  }
}
