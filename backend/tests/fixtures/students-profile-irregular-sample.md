# Students Profile (Irregular Derivation Fixture)

Local test roster for the irregular-student derivation tests (Task 4), refreshed on 2026-10-03 with
students from the seed roster, outlook for **2026-2027 · 1st Semester**. It is still
deliberately larger than students-profile-sample.md so the ~10% assertion window in
test_roughly_a_tenth_of_students_are_derived_as_irregular() has enough population to be
mathematically satisfiable, and it keeps its year-2/year-3 cohorts on the same sparse two-subject
curriculum students-profile-sample.md's fixtures already register (FM101S/FM201S, ACC101S/ACC301S)
rather than BSIT's dense per-term curriculum.

**How to read the columns.** `Year` is the student's year level at the end of 2025-2026 · 2nd
Semester (the seeder reads it as the student's year level); the Year-1 rows are the incoming 2026
entrants, who have no enrollment yet and open 2026-2027 · 1st Semester as first-year students.
`Category` is the outlook for the next school year, 2026-2027 · 1st Semester, using the rule in
`ClassifyEnrollmentStanding` (ADR 0028):

- **Regular** -- enrolled in 2025-2026 · 2nd Semester, classed Regular, and no failing mark
  (5.00 or DRP) in that term. Expected to stay Regular.
- **Possible Irregular** -- classed Regular today but carrying a 5.00 or DRP in 2025-2026 · 2nd
  Semester; a failed or dropped subject becomes a back subject, so the student may be Irregular
  once the next enrollment opens. It is only an outlook: the Program Chair and Registrar still
  decide, and nothing here denies an enrollment.

`Category` is for human scanning only. `StudentRosterReader` ignores it and the seeder derives its
own irregular cohort, so it is not authoritative grade evidence.

**Seeder selection.** `StudentRosterSeeder::selectIrregularCandidates()` forces every
`IRREGULAR_SELECTION_STRIDE`-based (10th) eligible year-2/3/4 student irregular. With 60 eligible
students (30 FM201 year-2 + 30 ACC301 year-3), exactly 6 are forced irregular (indices 0, 10, 20,
30, 40, 50). The rows marked Possible Irregular below sit on exactly those positions (the 1st, 11th
and 21st row of each block), so, on a fresh seed (ids follow file order), the fixture and the
seeder agree on who the 6 are. The IT101
year-1 block is only here so test_no_first_year_student_is_irregular() has a population to assert
zero against; it is intentionally small (10 rows) so it merely pads the denominator -- 6 irregular
out of 70 total (8.57%) lands inside the (0.07T, 0.13T) = (4, 9) window with real margin on both
sides. The Year-1 entrants have no grades at all, so all 10 are Regular.

**Source and limits.** 70 of the roughly 2,947 Regular students of the development database --
a sample, not the whole roster. They are the same seed students already listed in
`Subject And Prerequisuite/Students-Profile.md`, taken in Student No. order within each program and
year, with the Possible Irregular ones placed on the stride positions. The Possible Irregular
flags come from a read-only count of 5.00 / DRP marks in 2025-2026 · 2nd Semester on 2026-10-03;
they will drift as grades are locked and students are promoted.

## College of Business Administration and Entrepreneurship

### Year 2

#### FM201

| Student No. | Name | Email | Program | Section | Year | Category |
|---|---|---|---|---|---|---|
| 2025-06-01280 | Eduardo X. Matias | eduardo.matias@grc.com | BSBA-FM | FM201 | 2 | Possible Irregular |
| 2025-06-01271 | Janine H. Serrano | janine.serrano@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01272 | Delia A. Roxas | delia.roxas@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01274 | Jonathan D. Macaspac | jonathan.macaspac@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01275 | Bernadette U. Matias | bernadette.matias@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01276 | Isagani O. Antonio | isagani.antonio@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01278 | Charmaine B. Silangan | charmaine.silangan@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01279 | Jocelyn C. Ilagan | jocelyn.ilagan@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01281 | Ruben B. Mendoza | ruben.mendoza@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01282 | Gerardo A. Manlapaz | gerardo.manlapaz@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01298 | Justin Y. Villegas | justin.villegas@grc.com | BSBA-FM | FM201 | 2 | Possible Irregular |
| 2025-06-01283 | Marcelo L. Padua | marcelo.padua@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01285 | Jeffrey K. Vera | jeffrey.devera@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01286 | Ariel N. Scott | ariel.scott@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01287 | Janine L. Magsino | janine.magsino@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01289 | Minerva D. Cruz | minerva.cruz@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01291 | Imelda R. Mariano | imelda.mariano@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01292 | Princess L. Rosario | princess.delrosario@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01293 | Purita I. Ordonez | purita.ordonez@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01296 | Elvis X. Gaw | elvis.gaw@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01326 | Nathaniel L. Manlapaz | nathaniel.manlapaz@grc.com | BSBA-FM | FM201 | 2 | Possible Irregular |
| 2025-06-01297 | Julio L. Liwanag | julio.liwanag@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01299 | Rachelle J. Olalia | rachelle.olalia@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01300 | Bayani Y. Pena | bayani.pena@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01301 | Glenn W. Mariano | glenn.mariano@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01302 | Divine K. Sia | divine.sia@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01303 | Dolores X. Dumlao | dolores.dumlao@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01304 | Evangeline R. Dalisay | evangeline.dalisay@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01305 | Reynante D. Baltazar | reynante.baltazar@grc.com | BSBA-FM | FM201 | 2 | Regular |
| 2025-06-01306 | Dianne N. Vega | dianne.vega@grc.com | BSBA-FM | FM201 | 2 | Regular |

## College of Accountancy

### Year 3

#### ACC301

| Student No. | Name | Email | Program | Section | Year | Category |
|---|---|---|---|---|---|---|
| 2024-06-01465 | Guadalupe R. Salonga | guadalupe.salonga@grc.com | BSA | ACC301 | 3 | Possible Irregular |
| 2024-06-01453 | Cecilia A. Santos | cecilia.santos2@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01457 | Josephine U. Ramirez | josephine.ramirez@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01458 | Sharon C. Calungsod | sharon.calungsod@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01460 | Emmanuel I. Ortega | emmanuel.ortega@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01461 | Kevin I. Taylor | kevin.taylor@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01463 | Andres U. Uy | andres.uy@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01464 | Mark C. Yap | mark.yap@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01466 | Yolanda R. Kalaw | yolanda.kalaw@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01467 | Melinda U. King | melinda.king@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01482 | Salud P. Chiong | salud.chiong@grc.com | BSA | ACC301 | 3 | Possible Irregular |
| 2024-06-01468 | Consuelo U. Ortega | consuelo.ortega@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01470 | Leopoldo L. Zamora | leopoldo.zamora@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01472 | Cecilia P. Go | cecilia.go@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01473 | Bonifacio R. Guiao | bonifacio.guiao@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01474 | Bea H. Villanueva | bea.villanueva@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01477 | Trinidad P. Guerrero | trinidad.guerrero@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01480 | Melchor U. Cariaso | melchor.cariaso@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01481 | Purita E. Danao | purita.danao2@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01483 | Ignacio R. Magsino | ignacio.magsino@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01530 | Rosa Z. Malig | rosa.malig@grc.com | BSA | ACC301 | 3 | Possible Irregular |
| 2024-06-01484 | Grace N. Peralta | grace.peralta@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01485 | Ernesto O. Rosario | ernesto.rosario@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01486 | Juana A. Macabulos | juana.macabulos@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01487 | Aida P. Madrigal | aida.madrigal@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01488 | Mario S. Yap | mario.yap@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01489 | Jomel D. Sagun | jomel.sagun@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01490 | Socorro B. Ong | socorro.ong@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01493 | Liwayway G. Batac | liwayway.batac@grc.com | BSA | ACC301 | 3 | Regular |
| 2024-06-01494 | Gil W. Angeles | gil.angeles@grc.com | BSA | ACC301 | 3 | Regular |

## College of Computer Studies

### Year 1

#### IT101

| Student No. | Name | Email | Program | Section | Year | Category |
|---|---|---|---|---|---|---|
| 2026-06-01661 | Michael S. Batungbakal | michael.batungbakal@grc.com | BSIT | IT101 | 1 | Regular |
| 2026-06-01662 | Erlinda C. Robles | erlinda.robles2@grc.com | BSIT | IT101 | 1 | Regular |
| 2026-06-01663 | Reynaldo A. Reid | reynaldo.reid@grc.com | BSIT | IT101 | 1 | Regular |
| 2026-06-01664 | Consuelo Y. Wright | consuelo.wright@grc.com | BSIT | IT101 | 1 | Regular |
| 2026-06-01665 | Rosario B. Dalisay | rosario.dalisay@grc.com | BSIT | IT101 | 1 | Regular |
| 2026-06-01667 | Mario S. Concepcion | mario.concepcion@grc.com | BSIT | IT101 | 1 | Regular |
| 2026-06-01668 | Vivian R. Calderon | vivian.calderon@grc.com | BSIT | IT101 | 1 | Regular |
| 2026-06-01669 | Alfredo F. Bagsic | alfredo.bagsic@grc.com | BSIT | IT101 | 1 | Regular |
| 2026-06-01670 | Editha M. Katindig | editha.katindig@grc.com | BSIT | IT101 | 1 | Regular |
| 2026-06-01671 | Noel P. Ignacio | noel.ignacio@grc.com | BSIT | IT101 | 1 | Regular |

## Footer

**Total sections:** 3
**Total students:** 70
**Possible Irregular (2026-2027 · 1st Semester):** 6 (3 in FM201, 3 in ACC301)

Regenerated on 2026-10-03 from the development database (read-only) -- see StudentRosterSeederTest
for how it is consumed.
