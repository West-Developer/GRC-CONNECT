#!/usr/bin/env python3
"""Extracts the 2024-2029 curriculum placement map from GRC's raw block-section
schedule spreadsheets and writes curriculum-2024-2029-placements.csv.

Source: "Subject And Prerequisuite/2024 - 2029 Curriculum 1st Semester.xlsx"
and "...2nd Semester.xlsx" (repo root, untracked -- these are term *schedules*,
one worksheet per college (COE/CBAE/COA/CCS), one block section per named
range (e.g. "IT 301" = 3rd-year IT block 1). Every block of the same
(college, program track, year level) lists an identical subject roster, so
the curriculum map is the modal subject list per (college, track, year,
semester).

This is a one-off transcription tool, run manually and its CSV output
committed -- not invoked by any seeder at runtime, since the source
workbooks are binary .xlsx files that live outside the seeded data directory.

Subject codes are resolved against the EXISTING, already-tested
organizations-subjects-prerequisites.csv (the source
GrcSubjectCatalogSeeder/AllOrganizationsSubjectsPrerequisitesSeeder reads),
not emitted as raw Excel text: the two sources spell some codes differently
per block (e.g. "PATHFIT 1" vs "PATHFIT1", "UNDSELF" vs "UNSELF" -- the
latter pair are genuinely two distinct CBAE subjects, not a typo) and the
seeded `subjects.code` column holds that CSV's `subject_code` value verbatim.
Resolution uses the same key normalization already implicit in that CSV's
`subject_code_key` column (spaces/hyphens/periods stripped, uppercased) so a
placement always points at a subject GrcSubjectCatalogSeeder actually seeds.

Usage: python extract-curriculum-placements.py
(run from backend/database/seeders/data/; writes
curriculum-2024-2029-placements.csv alongside this script)
"""
from __future__ import annotations

import csv
import re
import sys
import zipfile
import xml.etree.ElementTree as ET
from collections import Counter, defaultdict
from pathlib import Path

NS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"

SCRIPT_DIR = Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parents[3]
SEM1_XLSX = REPO_ROOT / "Subject And Prerequisuite" / "2024 - 2029 Curriculum 1st Semester.xlsx"
SEM2_XLSX = REPO_ROOT / "Subject And Prerequisuite" / "2024 - 2029 Curriculum 2nd Semester.xlsx"
CATALOG_CSV = SCRIPT_DIR / "organizations-subjects-prerequisites.csv"
OUTPUT_CSV = SCRIPT_DIR / "curriculum-2024-2029-placements.csv"

# Maps a block-name track prefix (e.g. "IT" in "IT 301") to the real program
# code GrcCurriculumSeeder/ProgramSeeder use. "EDUC" is not a program -- it is
# COE's shared first-year 1st-semester general-education block, fanned out to
# every COE major below (see EDUC_FANOUT_PROGRAMS). "SOC" and "SOCSCI" are the
# same program spelled two ways in the source sheets.
TRACK_TO_PROGRAM: dict[str, str | None] = {
    "EDUC": None,
    "ELEM": "BEED",
    "FIL": "BSED-FIL",
    "ENG": "BSED-ENG",
    "SOCSCI": "BSED-SOCSCI",
    "SOC": "BSED-SOCSCI",
    "VAL": "BSED-VAL",
    "TCP": "TCP",
    "FM": "BSBA-FM",
    "EN": "BSENTREP",
    "MM": "BSBA-MM",
    "HR": "BSBA-HRM",
    "ACC": "BSA",
    "IT": "BSIT",
}

# The five full 4-year COE majors that share the EDUC common block as their
# year-1 1st-semester placement. TCP (a 6-subject certificate program with no
# year levels of its own) deliberately does not receive it.
EDUC_FANOUT_PROGRAMS = ["BEED", "BSED-FIL", "BSED-ENG", "BSED-SOCSCI", "BSED-VAL"]

PROGRAM_TO_COLLEGE = {
    "BEED": "COE", "BSED-FIL": "COE", "BSED-ENG": "COE", "BSED-SOCSCI": "COE",
    "BSED-VAL": "COE", "TCP": "COE",
    "BSBA-FM": "CBAE", "BSENTREP": "CBAE", "BSBA-MM": "CBAE", "BSBA-HRM": "CBAE",
    "BSA": "COA",
    "BSIT": "CCS",
}

BLOCK_NAME_RE = re.compile(r"^([A-Za-z]+)\s*(\d)(\d+)")


def normalize_key(code: str) -> str:
    return re.sub(r"[ \-.]", "", code.strip()).upper()


def load_catalog_index() -> dict[str, dict[str, str]]:
    """@return {college: {normalized_key: canonical subject_code}}"""
    index: dict[str, dict[str, str]] = defaultdict(dict)
    with open(CATALOG_CSV, encoding="utf-8-sig", newline="") as fh:
        for row in csv.DictReader(fh):
            college = row["organization"].strip().upper()
            key = row["subject_code_key"].strip().upper()
            index[college][key] = row["subject_code"].strip()
    return index


def load_workbook_sheets(path: Path) -> dict[str, list[dict[str, str]]]:
    """@return {sheet name: [row cell-dicts]}, columns keyed by letter."""
    with zipfile.ZipFile(path) as zf:
        shared_strings = []
        root = ET.fromstring(zf.read("xl/sharedStrings.xml"))
        for si in root.findall(NS + "si"):
            shared_strings.append("".join(t.text or "" for t in si.iter(NS + "t")))

        workbook = ET.fromstring(zf.read("xl/workbook.xml"))
        sheet_names = [s.get("name") for s in workbook.iter(NS + "sheet")]

        sheets: dict[str, list[dict[str, str]]] = {}
        for index, name in enumerate(sheet_names, start=1):
            rows: list[dict[str, str]] = []
            sheet_xml = ET.fromstring(zf.read(f"xl/worksheets/sheet{index}.xml"))
            for row in sheet_xml.iter(NS + "row"):
                cells: dict[str, str] = {}
                for cell in row.findall(NS + "c"):
                    column = re.match(r"[A-Z]+", cell.get("r")).group()
                    value_el = cell.find(NS + "v")
                    if value_el is None:
                        continue
                    cells[column] = (
                        shared_strings[int(value_el.text)]
                        if cell.get("t") == "s"
                        else value_el.text
                    )
                rows.append(cells)
            sheets[name] = rows
        return sheets


def blocks_in_sheet(rows: list[dict[str, str]]) -> "dict[str, list[str]]":
    """Groups a sheet's rows into {block name: [raw subject codes]}, skipping
    header/nothing-follows/label rows the same way for both semesters."""
    blocks: dict[str, list[str]] = {}
    current: str | None = None
    for cells in rows:
        block_name = (cells.get("A") or "").strip()
        if block_name and block_name != " " and block_name.upper() != "SECTION":
            current = block_name
            blocks.setdefault(current, [])
        code = (cells.get("B") or "").strip()
        description = (cells.get("C") or "").strip()
        if current is None or not code or not description:
            continue
        if code == "SUBJECT CODE" or "NOTHING FOLLOWS" in code.upper() or code == current:
            continue
        blocks[current].append(code)
    return blocks


def track_and_year(block_name: str) -> tuple[str, int] | None:
    name = block_name.strip().upper()
    if name.startswith("TCP"):
        return "TCP", 1
    match = BLOCK_NAME_RE.match(name)
    if not match:
        return None
    return match.group(1).upper(), int(match.group(2))


def resolve_subject(catalog: dict[str, dict[str, str]], college: str, raw_code: str) -> str | None:
    return catalog.get(college, {}).get(normalize_key(raw_code))


def main() -> None:
    catalog = load_catalog_index()

    # {(college, track, year): {semester: Counter(frozenset(resolved codes) -> occurrences)}}
    variants: dict[tuple[str, str, int], dict[str, Counter]] = defaultdict(
        lambda: defaultdict(Counter)
    )
    unresolved: list[str] = []

    for path, semester in [(SEM1_XLSX, "1st"), (SEM2_XLSX, "2nd")]:
        sheets = load_workbook_sheets(path)
        for college, rows in sheets.items():
            for block_name, raw_codes in blocks_in_sheet(rows).items():
                parsed = track_and_year(block_name)
                if parsed is None:
                    print(f"WARN: unparsed block name {college}/{block_name!r}", file=sys.stderr)
                    continue
                track, year = parsed
                resolved: list[str] = []
                for raw_code in raw_codes:
                    subject_code = resolve_subject(catalog, college, raw_code)
                    if subject_code is None:
                        unresolved.append(f"{college}/{block_name}: {raw_code!r}")
                        continue
                    resolved.append(subject_code)
                variants[(college, track, year)][semester][frozenset(resolved)] += 1

    # {(college, program_code, year, semester): set(subject_code)}
    placements: dict[tuple[str, str, int, str], set[str]] = defaultdict(set)

    for (college, track, year), by_semester in variants.items():
        program = TRACK_TO_PROGRAM.get(track)
        for semester, counter in by_semester.items():
            modal_codes = set(counter.most_common(1)[0][0])
            if track == "EDUC":
                for fanout_program in EDUC_FANOUT_PROGRAMS:
                    placements[(college, fanout_program, 1, semester)] |= modal_codes
                continue
            if program is None:
                print(f"WARN: no program mapping for track {track!r}", file=sys.stderr)
                continue
            placements[(college, program, year, semester)] |= modal_codes

    with open(OUTPUT_CSV, "w", newline="", encoding="utf-8") as out:
        writer = csv.writer(out)
        writer.writerow(["college", "program_code", "year_level", "semester", "subject_code"])
        for (college, program, year, semester), codes in sorted(placements.items()):
            for code in sorted(codes):
                writer.writerow([college, program, year, semester, code])

    total_rows = sum(len(codes) for codes in placements.values())
    print(f"Wrote {total_rows} placement rows across {len(placements)} program-year-semester groups to {OUTPUT_CSV}")
    if unresolved:
        print(f"{len(unresolved)} unresolved subject codes (not written):", file=sys.stderr)
        for entry in unresolved:
            print(f"  {entry}", file=sys.stderr)


if __name__ == "__main__":
    main()
