/**
 * Prints one region of the page (a COR, a prospectus, a grade slip, a report) in its own
 * isolated, hidden iframe instead of printing the whole portal page and hiding everything
 * else with CSS.
 *
 * Why: the old approach set a flag on `<body>` and relied on a long hide-list in
 * `globals.css`. That list drifted from the real markup (a new header, a re-skinned dialog) and
 * the print came out with the portal header and buttons showing and the document shifted off the
 * page. In an iframe only the region exists, laid out at the paper's own width, so there is
 * nothing to hide and nothing to drift. The app's stylesheets are copied in so the `.cor-document`
 * print rules (keyed on `body[data-printing="document"]`) and the Tailwind `print:` utilities
 * still apply.
 */

const FRAME_ATTRIBUTE = "data-print-frame"
const READY_TIMEOUT_MS = 4000
const REMOVE_AFTER_PRINT_MS = 500

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => window.setTimeout(resolve, ms))
}

export function removePrintFrames(): void {
  document
    .querySelectorAll(`iframe[${FRAME_ATTRIBUTE}]`)
    .forEach((frame) => frame.remove())
}

/** Resolves once every copied stylesheet, image and font has loaded (or the timeout passes). */
async function waitUntilReady(
  doc: Document,
  stylesheets: readonly Promise<void>[],
): Promise<void> {
  const images = Array.from(doc.images).map(
    (image) =>
      new Promise<void>((resolve) => {
        if (image.complete) return resolve()
        image.addEventListener("load", () => resolve(), { once: true })
        image.addEventListener("error", () => resolve(), { once: true })
      }),
  )
  const fonts = doc.fonts?.ready ?? Promise.resolve()
  await Promise.race([
    Promise.all([...stylesheets, ...images, fonts]).then(() => undefined),
    delay(READY_TIMEOUT_MS),
  ])
}

export async function printRegion(
  region: HTMLElement,
  title?: string,
): Promise<void> {
  removePrintFrames()

  const frame = document.createElement("iframe")
  frame.setAttribute(FRAME_ATTRIBUTE, "")
  frame.setAttribute("aria-hidden", "true")
  frame.tabIndex = -1
  frame.title = "Print preview"
  frame.style.cssText =
    "position:fixed;right:0;bottom:0;width:0;height:0;border:0;"
  frame.srcdoc =
    '<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>'
  const loaded = new Promise<void>((resolve) => {
    frame.addEventListener("load", () => resolve(), { once: true })
    // A browser that never fires `load` for a srcdoc frame must not hang the button.
    window.setTimeout(resolve, 1000)
  })
  document.body.appendChild(frame)
  await loaded

  const win = frame.contentWindow
  const doc = frame.contentDocument
  if (!win || !doc) {
    frame.remove()
    throw new Error("The print frame could not be created.")
  }

  doc.title = title ?? document.title
  doc.documentElement.lang = document.documentElement.lang
  // Always print on white: never carry the portal's dark theme into the document.
  doc.documentElement.className = document.documentElement.className
    .split(/\s+/)
    .filter((name) => name !== "dark")
    .join(" ")
  doc.body.className = document.body.className
  doc.body.setAttribute("data-printing", "document")

  const stylesheets: Promise<void>[] = []
  document
    .querySelectorAll<HTMLElement>('link[rel="stylesheet"], style')
    .forEach((node) => {
      const copy = doc.importNode(node, true)
      if (copy instanceof HTMLLinkElement) {
        stylesheets.push(
          new Promise<void>((resolve) => {
            copy.addEventListener("load", () => resolve(), { once: true })
            copy.addEventListener("error", () => resolve(), { once: true })
          }),
        )
      }
      doc.head.appendChild(copy)
    })
  doc.body.appendChild(doc.importNode(region, true))

  await waitUntilReady(doc, stylesheets)

  win.addEventListener(
    "afterprint",
    () => window.setTimeout(() => frame.remove(), REMOVE_AFTER_PRINT_MS),
    { once: true },
  )
  win.focus()
  win.print()
}
