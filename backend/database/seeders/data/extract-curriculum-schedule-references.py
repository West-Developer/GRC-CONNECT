#!/usr/bin/env python3
"""Extracts real per-subject schedule + faculty reference data (day, time, room,
modality, professor name, sched id, and the schedule-changes notes column) from
GRC's raw block-section schedule spreadsheets, for the REPRESENTATIVE (lowest-
numbered) block of each (college, program, year_level, semester) group, across all
12 real programs. Writes curriculum-2024-2029-schedule-references.csv.

Sibling to extract-curriculum-placements.py (reuses its block-detection logic).
Everything from the source sheet is captured except the Google Classroom column
-- confirmed scope. A blank faculty-name cell in the source stays blank in the
output; there is deliberately no fallback search across a program-year's other
blocks for a name.

Usage: python extract-curriculum-schedule-references.py
(run from backend/database/seeders/data/; writes
curriculum-2024-2029-schedule-references.csv alongside this script)
"""
from __future__ import annotations

import csv
import re
import sys
import zipfile
import xml.etree.ElementTree as ET
from collections import defaultdict
from pathlib import Path

NS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"

SCRIPT_DIR = Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parents[3]
SEM1_XLSX = REPO_ROOT / "Subject And Prerequisuite" / "2024 - 2029 Curriculum 1st Semester.xlsx"
SEM2_XLSX = REPO_ROOT / "Subject And Prerequisuite" / "2024 - 2029 Curriculum 2nd Semester.xlsx"
CATALOG_CSV = SCRIPT_DIR / "organizations-subjects-prerequisites.csv"
OUTPUT_CSV = SCRIPT_DIR / "curriculum-2024-2029-schedule-references.csv"

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
EDUC_FANOUT_PROGRAMS = ["BEED", "BSED-FIL", "BSED-ENG", "BSED-SOCSCI", "BSED-VAL"]

# Hard-coded column mappings per (college, semester) pair.
# CCS and COA have different layouts in 1st vs 2nd semester; COE/CBAE are consistent.
# These values are derived by reading the actual header row (row where B="SUBJECT CODE")
# from each of the 8 (college × semester) sheet pairs.
COLUMNS = {
    ("CCS", "1st"): {"day": "E", "time": "F", "sched_id": "G", "room": "H", "faculty": "I", "modality": "K", "notes": "L"},
    ("CCS", "2nd"): {"day": "E", "time": "F", "sched_id": "G", "room": "H", "faculty": "K", "modality": "I", "notes": "J"},
    ("COE", "1st"): {"day": "E", "time": "F", "sched_id": "D", "room": "H", "faculty": "I", "modality": None, "notes": "K"},
    ("COE", "2nd"): {"day": "E", "time": "F", "sched_id": "D", "room": "H", "faculty": "I", "modality": None, "notes": "K"},
    ("CBAE", "1st"): {"day": "E", "time": "F", "sched_id": "D", "room": "H", "faculty": "I", "modality": None, "notes": "K"},
    ("CBAE", "2nd"): {"day": "E", "time": "F", "sched_id": "D", "room": "H", "faculty": "I", "modality": None, "notes": "K"},
    ("COA", "1st"): {"day": "E", "time": "F", "sched_id": "D", "room": "H", "faculty": "I", "modality": None, "notes": "K"},
    ("COA", "2nd"): {"day": "E", "time": "F", "sched_id": "D", "room": "I", "faculty": "J", "modality": None, "notes": "L"},
}

BLOCK_NAME_RE = re.compile(r"^([A-Za-z]+)\s*(\d)(\d+)")


def normalize_key(code: str) -> str:
    return re.sub(r"[ \-.]", "", code.strip()).upper()


def load_catalog_index() -> dict[str, dict[str, str]]:
    index: dict[str, dict[str, str]] = defaultdict(dict)
    with open(CATALOG_CSV, encoding="utf-8-sig", newline="") as fh:
        for row in csv.DictReader(fh):
            college = row["organization"].strip().upper()
            key = row["subject_code_key"].strip().upper()
            index[college][key] = row["subject_code"].strip()
    return index


def load_workbook_sheets(path: Path) -> dict[str, list[dict[str, str]]]:
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


def blocks_in_sheet(rows: list[dict[str, str]]) -> dict[str, list[dict[str, str]]]:
    """@return {block name: [row cell-dicts for its subject rows]}"""
    blocks: dict[str, list[dict[str, str]]] = {}
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
        blocks[current].append(cells)
    return blocks


def track_year_ordinal(block_name: str) -> tuple[str, int, int] | None:
    """@return (track, year_level, block_ordinal) or None if unparsed."""
    name = block_name.strip().upper()
    if name.startswith("TCP"):
        return "TCP", 1, 1
    match = BLOCK_NAME_RE.match(name)
    if not match:
        return None
    return match.group(1).upper(), int(match.group(2)), int(match.group(3))


def resolve_subject(catalog: dict[str, dict[str, str]], college: str, raw_code: str) -> str | None:
    return catalog.get(college, {}).get(normalize_key(raw_code))


def main() -> None:
    catalog = load_catalog_index()

    per_group: dict[tuple[str, str, int, str], tuple[int, list[dict[str, str]]]] = {}
    for path, semester in [(SEM1_XLSX, "1st"), (SEM2_XLSX, "2nd")]:
        sheets = load_workbook_sheets(path)
        for college, rows in sheets.items():
            for block_name, block_rows in blocks_in_sheet(rows).items():
                parsed = track_year_ordinal(block_name)
                if parsed is None:
                    continue
                track, year, ordinal = parsed
                key = (college, track, year, semester)
                current_best = per_group.get(key)
                if current_best is None or ordinal < current_best[0]:
                    per_group[key] = (ordinal, block_rows)

    output_rows: list[list[str]] = []
    columns_missing_warned: set[tuple[str, str]] = set()
    unresolved: list[str] = []

    for (college, track, year, semester), (_ordinal, block_rows) in per_group.items():
        program = TRACK_TO_PROGRAM.get(track)
        target_programs = EDUC_FANOUT_PROGRAMS if track == "EDUC" else ([program] if program else [])
        if not target_programs:
            if track != "EDUC" and program is None:
                print(f"WARN: no program mapping for track {track!r}", file=sys.stderr)
            continue

        columns = COLUMNS.get((college, semester))
        if columns is None:
            if (college, semester) not in columns_missing_warned:
                print(f"WARN: no column layout for college {college!r} semester {semester!r}", file=sys.stderr)
                columns_missing_warned.add((college, semester))
            continue

        for row in block_rows:
            raw_code = (row.get("B") or "").strip()
            subject_code = resolve_subject(catalog, college, raw_code)
            if subject_code is None:
                unresolved.append(f"{college}/{track}{year}/{semester}: {raw_code!r}")
                continue

            day = (row.get(columns["day"]) or "").strip()
            time_range = (row.get(columns["time"]) or "").strip()
            start_time, end_time = "", ""
            if "-" in time_range:
                start_time, end_time = (part.strip() for part in time_range.split("-", 1))
            sched_id = (row.get(columns["sched_id"]) or "").strip()
            room = (row.get(columns["room"]) or "").strip()
            faculty = (row.get(columns["faculty"]) or "").strip()
            modality = (row.get(columns["modality"]) or "").strip() if columns["modality"] else ""
            notes = (row.get(columns["notes"]) or "").strip()

            for target_program in target_programs:
                output_rows.append([
                    college, target_program, str(year), semester, subject_code,
                    day, start_time, end_time, room, modality, faculty, sched_id, notes,
                ])

    with open(OUTPUT_CSV, "w", newline="", encoding="utf-8") as out:
        writer = csv.writer(out)
        writer.writerow([
            "college", "program_code", "year_level", "semester", "subject_code",
            "day", "start_time", "end_time", "room", "modality", "professor_name",
            "sched_id", "notes",
        ])
        for row in sorted(output_rows, key=lambda r: (r[0], r[1], r[2], r[3], r[4])):
            writer.writerow(row)

    print(f"Wrote {len(output_rows)} schedule-reference rows to {OUTPUT_CSV}")
    if unresolved:
        print(f"{len(unresolved)} unresolved subject codes (not written):", file=sys.stderr)
        for entry in unresolved:
            print(f"  {entry}", file=sys.stderr)


if __name__ == "__main__":
    main()
