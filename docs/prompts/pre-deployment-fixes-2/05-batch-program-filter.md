# 05 — New Batch Request: a Program dropdown in Select Students

Branch: `feature/pre-deployment-fixes-2`. **Do not commit.** Stop at the
verification block and wait for Nat's `commit`.

No decision. **Amends FR-ADM-03.** No schema change, no new package, no new
route.

## Why

Nat: "on the College Admin's New Batch Request, on the third card (Select
Students), add a dropdown for each program; the student list then reflects
that choice. Put the dropdown to the left of the search bar, like on the
clinic dashboard."

"Like on the clinic dashboard" means the **Encode History** filter bar in
`resources/views/nurse/dashboard.blade.php:66–109`: a small uppercase label
above each `<select>` (`text-[11px] font-semibold uppercase tracking-widest
text-hp-slate/40`), and the select's classes `rounded-lg border-hp-slate/20
bg-hp-white py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate
focus:border-hp-orange focus:ring-hp-orange`, sitting left of a flexible
search box. Copy that look. The mechanics are different (see below).

## What is there now

- `Admin\BatchRequestController::create()` (~line 135) ships the whole
  college-scoped roster once, as JSON rows
  `{id, number, name, course, year, search}`. `course` is the student's program
  (a `config/programs.php` catalog string since the advisor revisions).
- `resources/views/admin/batches/create.blade.php`: the Alpine `batchForm()`
  filters that roster **in the browser** (`get filtered()`, ~line 132, by
  `query`). `shown` caps rendered rows at 150. **Select All** selects every
  *filtered* student (`selectAllFiltered()`). **Clear** empties the whole
  selection. The selection survives any filtering.
- The search input is at ~line 686–696 inside the "Select Students" card
  (~line 653).

## What to do (settled with Nat)

Nat chose the **full college catalog**: the dropdown lists "All programs" plus
**every** program `config/programs.php` has for this college, even programs
with no registered student yet.

1. **Controller:** pass `'programs' => Programs::forCollege($college->id)`
   (`App\Support\Programs`) to the view. It's the same catalog the
   registration form uses. Don't derive the list from the roster.
2. **View:** turn the search row into a flex row, the way the nurse dashboard's
   filter bar is: a `Program` select on the **left** (label above it), the
   existing search box to its right taking the remaining width. On phones they
   may wrap, select above search. Options: `All programs` (value `''`),
   then each catalog program, in catalog order, as printed.
3. **Alpine:** a new `program: ''` field. `filtered` keeps a student only if
   it matches **both** the program (when one is chosen, `s.course === program`)
   **and** the search. Everything downstream already reads `filtered`, so:
   - **Select All** now means "everyone in this program matching the search";
     don't change it, it already does this;
   - the selection **survives** switching programs (a student picked under BSIT
     stays picked while BSCS is shown). Keep it that way;
   - the "(N of M selected)" count keeps M = the whole roster.
4. **Empty result:** today there's a "No students match "…"" line for an empty
   search. With a program chosen and nobody in it (possible, since the whole
   catalog is listed), show `No students registered in <program> yet.` When
   both a program and a search are active and nothing matches, the existing
   no-match line is enough.
5. The program filter is **browser-only**: it is not posted, not validated,
   not in `old()`, and after a failed submit the dropdown starts on
   "All programs" again. That's fine. Don't add a hidden input.

Legacy students whose `course` is not in today's catalog (from before the
advisor revisions) still appear under **All programs**; they just don't match
any single program. Don't add an "Other" option for them. Mention in your
report how many such students the dev DB has, if any
(`StudentProfile::whereNotIn('course', …)` per college in tinker).

## Out of scope

- A year-level filter (not asked).
- Server-side filtering or pagination of the roster.
- The clash popup (prompt 06).

## Docs to update in this same change

- `docs/HealthPass_PRD.md` **FR-ADM-03**: add *"…and filterable by program
  with a Program dropdown (All programs + the college's catalog programs from
  `config/programs.php`) left of the search box; Select All acts on the
  filtered list; the selection is kept across filter changes (2026-09-23)."*
- Next **revision-history row**. No schema change.
- `docs/HealthPass_Context.md` → COLLEGE ADMIN → New Batch Request: the same
  line.
- `CHANGELOG.md`: one entry.

## Tests

`tests/Feature/Admin/…` (find the create-page test next to `BatchRequestSubmitTest.php`):
- the create page lists every `Programs::forCollege()` program as an `<option>`
  for the admin's college, and **not** another college's programs;
- `All programs` is present.

The filtering is Alpine, so check it by hand.

## Verify

1. `php artisan serve --port=8080`, `npm run dev`. Log in as a College Admin
   whose college has students in at least two programs.
2. New Batch Request → Select Students: the Program dropdown sits left of the
   search box and looks like the clinic dashboard's filters.
3. Choose a program: only its students show. Type part of a name: both filters
   apply. **Select All** picks only those. Switch to another program: the earlier
   picks are still counted in "(N of M selected)".
4. Choose a catalog program with no students: the "No students registered in
   … yet." line.
5. Submit a valid batch picked across two programs: every picked student is
   on it (the filter never drops selected students from the POST).
6. 375px wide: the select and search wrap cleanly, with no horizontal scroll.
7. `php artisan test`: full suite green.

Then **stop and report**. Wait for `commit`.

Proposed commit message:

```
feat: filter the batch student picker by program (FR-ADM-03)
```
