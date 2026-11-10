import { GuideText } from "@/features/components/guide/guide-text"
import { userGuideShots } from "@/features/lib/user-guide/shots"

/**
 * A real screenshot of the portal with its caption. Markers are drawn from
 * percentages stored with the shot, so they stay on the right control at every
 * size, in the page and in the PDF.
 */
export function GuideFigure({
  shotId,
  caption,
}: {
  shotId: string
  caption: string
}) {
  const shot = userGuideShots[shotId]

  if (!shot) {
    return null
  }

  return (
    <figure className="guide-figure">
      <div className="guide-figure__frame">
        {/* A plain img on purpose: the PDF is the printed page, and these are
            already-optimised JPEGs with fixed dimensions. */}
        <img
          src={shot.src}
          width={shot.width}
          height={shot.height}
          alt={shot.alt}
          loading="lazy"
          decoding="async"
        />
        {shot.marks?.map((mark) => (
          <span
            key={mark.n}
            className="guide-mark"
            aria-hidden="true"
            style={{
              left: `${mark.x}%`,
              top: `${mark.y}%`,
              width: `${mark.w}%`,
              height: `${mark.h}%`,
            }}
          >
            <span className="guide-mark__n">{mark.n}</span>
          </span>
        ))}
      </div>
      <figcaption>
        <GuideText text={caption} />
      </figcaption>
    </figure>
  )
}

export function GuidePhones({
  shotIds,
  caption,
}: {
  shotIds: readonly string[]
  caption: string
}) {
  const shots = shotIds.flatMap((id) => {
    const shot = userGuideShots[id]
    return shot ? [shot] : []
  })

  if (shots.length === 0) {
    return null
  }

  return (
    <figure className="guide-figure guide-figure--phones">
      <div className="guide-phones">
        {shots.map((shot) => (
          <img
            key={shot.src}
            src={shot.src}
            width={shot.width}
            height={shot.height}
            alt={shot.alt}
            loading="lazy"
            decoding="async"
          />
        ))}
      </div>
      <figcaption>
        <GuideText text={caption} />
      </figcaption>
    </figure>
  )
}
