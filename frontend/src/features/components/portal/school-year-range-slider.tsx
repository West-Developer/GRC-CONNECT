"use client"

interface SchoolYearRangeSliderProps {
  schoolYears: readonly string[]
  startSchoolYear: string | null
  endSchoolYear: string | null
  onRangeChange: (range: {
    startSchoolYear: string | null
    endSchoolYear: string | null
  }) => void
}

function indexFor(
  years: readonly string[],
  value: string | null,
  fallback: number,
) {
  const index = value === null ? -1 : years.indexOf(value)
  return index < 0 ? fallback : index
}

/**
 * Two native range inputs share one track so the Program Chair can set an
 * inclusive school-year window with keyboard-accessible, volume-style thumbs.
 */
export function SchoolYearRangeSlider({
  schoolYears,
  startSchoolYear,
  endSchoolYear,
  onRangeChange,
}: SchoolYearRangeSliderProps) {
  if (schoolYears.length === 0) return null

  const lastIndex = schoolYears.length - 1
  const startIndex = indexFor(schoolYears, startSchoolYear, 0)
  const endIndex = indexFor(schoolYears, endSchoolYear, lastIndex)
  const safeStartIndex = Math.min(startIndex, endIndex)
  const safeEndIndex = Math.max(startIndex, endIndex)
  const startPercent = (safeStartIndex / Math.max(lastIndex, 1)) * 100
  const endPercent = (safeEndIndex / Math.max(lastIndex, 1)) * 100

  return (
    <section
      aria-label="Analytics school-year range"
      className="grid gap-3 rounded-xl border bg-muted/20 p-4"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-semibold">Analytics school-year range</p>
          <p className="text-xs text-muted-foreground">
            Move both handles to include the details and trend below.
          </p>
        </div>
        <output className="rounded-full border bg-background px-2.5 py-1 text-xs font-medium tabular-nums">
          {schoolYears[safeStartIndex]} — {schoolYears[safeEndIndex]}
        </output>
      </div>
      <div className="relative h-8">
        <div className="absolute inset-x-1 top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-border" />
        <div
          className="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-primary"
          style={{ left: `${startPercent}%`, right: `${100 - endPercent}%` }}
        />
        <input
          aria-label="Start school year"
          aria-valuetext={schoolYears[safeStartIndex]}
          className="school-year-range-thumb pointer-events-none absolute inset-0 h-8 w-full appearance-none bg-transparent"
          type="range"
          min={0}
          max={lastIndex}
          value={safeStartIndex}
          disabled={lastIndex === 0}
          onChange={(event) => {
            const nextStart = Math.min(Number(event.target.value), safeEndIndex)
            onRangeChange({
              startSchoolYear: schoolYears[nextStart],
              endSchoolYear: schoolYears[safeEndIndex],
            })
          }}
        />
        <input
          aria-label="End school year"
          aria-valuetext={schoolYears[safeEndIndex]}
          className="school-year-range-thumb pointer-events-none absolute inset-0 h-8 w-full appearance-none bg-transparent"
          type="range"
          min={0}
          max={lastIndex}
          value={safeEndIndex}
          disabled={lastIndex === 0}
          onChange={(event) => {
            const nextEnd = Math.max(Number(event.target.value), safeStartIndex)
            onRangeChange({
              startSchoolYear: schoolYears[safeStartIndex],
              endSchoolYear: schoolYears[nextEnd],
            })
          }}
        />
      </div>
      <div className="flex justify-between text-xs text-muted-foreground">
        <span>Start: {schoolYears[safeStartIndex]}</span>
        <span>End: {schoolYears[safeEndIndex]}</span>
      </div>
    </section>
  )
}
