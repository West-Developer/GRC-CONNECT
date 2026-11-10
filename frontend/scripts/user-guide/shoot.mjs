// One screenshot of the current page: JPEG in public/user-guide/<id>.jpg plus
// its entry (size, alt text, numbered markers) in the guide's shots.json.
import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

import sharp from "sharp"

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, "../..")
const imageDir = path.join(root, "public/user-guide")
const manifestPath = path.join(root, "src/features/lib/user-guide/shots.json")

export function createShooter() {
  fs.mkdirSync(imageDir, { recursive: true })
  const manifest = {}

  /** Percent box for one marker, measured from the live element. */
  async function measure(page, mark, viewport) {
    const locator = page.locator(mark.selector).nth(mark.nth ?? 0)
    const box = await locator.boundingBox({ timeout: 8_000 }).catch(() => null)
    if (!box) {
      console.warn(`  ! no box for marker ${mark.n} (${mark.selector})`)
      return null
    }
    // A marker for something below the fold would point at nothing.
    if (
      box.y < 0 ||
      box.x < 0 ||
      box.y + box.height > viewport.height ||
      box.x + box.width > viewport.width
    ) {
      console.warn(
        `  ! marker ${mark.n} (${mark.selector}) is outside the picture`,
      )
      return null
    }
    const pad = mark.pad ?? 5
    const x = Math.max(0, box.x - pad)
    const y = Math.max(0, box.y - pad)
    const w = Math.min(viewport.width - x, box.width + pad * 2)
    const h = Math.min(viewport.height - y, box.height + pad * 2)
    const round = (v) => Math.round(v * 100) / 100
    return {
      n: mark.n,
      x: round((x / viewport.width) * 100),
      y: round((y / viewport.height) * 100),
      w: round((w / viewport.width) * 100),
      h: round((h / viewport.height) * 100),
    }
  }

  async function shoot(page, { id, alt, marks = [], mobile = false }) {
    const viewport = page.viewportSize()
    const measured = []
    for (const mark of marks) {
      const box = await measure(page, mark, viewport)
      if (box) measured.push(box)
    }
    const png = await page.screenshot({ type: "png" })
    const jpeg = await sharp(png)
      .jpeg({
        quality: mobile ? 78 : 84,
        chromaSubsampling: "4:4:4",
        mozjpeg: true,
      })
      .toBuffer()
    fs.writeFileSync(path.join(imageDir, `${id}.jpg`), jpeg)
    const scale = mobile ? 2 : 1
    manifest[id] = {
      src: `/user-guide/${id}.jpg`,
      width: viewport.width * scale,
      height: viewport.height * scale,
      alt,
      ...(measured.length ? { marks: measured } : {}),
    }
    console.log(
      `shot ${id} ${(jpeg.length / 1024).toFixed(0)}KB ${measured.length}/${marks.length} marks`,
    )
    save()
  }

  // Re-read before writing so two tools run one after the other (or briefly
  // overlapping) never drop each other's entries.
  function save() {
    const onDisk = fs.existsSync(manifestPath)
      ? JSON.parse(fs.readFileSync(manifestPath, "utf8"))
      : {}
    const merged = { ...onDisk, ...manifest }
    const sorted = Object.fromEntries(
      Object.entries(merged).sort(([a], [b]) => a.localeCompare(b)),
    )
    fs.writeFileSync(manifestPath, `${JSON.stringify(sorted, null, 2)}\n`)
  }

  return { shoot, manifest }
}
