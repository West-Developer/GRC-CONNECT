import { Badge } from "@/features/components/ui/badge"

/**
 * The text of a curriculum pill: "BS Information Technology Curriculum
 * 2024-2029" reads as "BS Information Technology 2024-2029". The effective
 * school year is only appended when the name does not already carry it, so
 * the label never repeats "2024-2029 (2024-2029)".
 */
export function curriculumPillText(
  name: string,
  options: { effectiveSchoolYear?: string | null; suffix?: string } = {},
): string {
  const base = name
    .replace(/\s*Curriculum\s*/i, " ")
    .replace(/\s+/g, " ")
    .trim()
  const year = options.effectiveSchoolYear
  const withYear = year && !base.includes(year) ? `${base} (${year})` : base

  return options.suffix ? `${withYear} · ${options.suffix}` : withYear
}

/**
 * Curriculum label on a section card. It sits on its own row and wraps: the
 * base Badge is a one-line, clipped chip, which cut a long curriculum name
 * off mid-word ("Information Technology 2024-2029 (2024-20…") on the narrow
 * section cards. The full name stays in the title as well.
 */
export function CurriculumPill({
  name,
  effectiveSchoolYear,
  suffix,
}: {
  name: string
  effectiveSchoolYear?: string | null
  suffix?: string
}) {
  return (
    <Badge
      variant="outline"
      title={name}
      className="h-auto min-h-5 w-full max-w-full justify-center rounded-xl border-primary/30 bg-primary/5 px-2 py-0.5 text-center text-[10px] leading-tight font-normal whitespace-normal text-primary"
    >
      <span className="break-words">
        {curriculumPillText(name, { effectiveSchoolYear, suffix })}
      </span>
    </Badge>
  )
}
