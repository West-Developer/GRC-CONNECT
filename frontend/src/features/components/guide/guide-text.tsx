import { Fragment, type ReactNode } from "react"

/**
 * Renders the two bits of inline markup the guide content uses:
 *   **bold**  a label exactly as it appears on screen
 *   `code`    something the reader types or an address
 *
 * The content is written by us and typed, so there is no HTML here and nothing
 * to sanitise. Unclosed markers are printed as plain text.
 */
const INLINE_PATTERN = /(\*\*[^*]+\*\*|`[^`]+`)/g

export function GuideText({ text }: { text: string }): ReactNode {
  const parts = text.split(INLINE_PATTERN)

  return (
    <>
      {parts.map((part, index) => {
        if (part.length > 4 && part.startsWith("**") && part.endsWith("**")) {
          return <strong key={index}>{part.slice(2, -2)}</strong>
        }
        if (part.length > 2 && part.startsWith("`") && part.endsWith("`")) {
          return <code key={index}>{part.slice(1, -1)}</code>
        }
        return <Fragment key={index}>{part}</Fragment>
      })}
    </>
  )
}
