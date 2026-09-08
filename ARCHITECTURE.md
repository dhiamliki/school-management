# Architecture

How Gestion Scolaire is put together, and why. `README.md` covers installation and features; this document covers structure and the reasoning behind it.

---

## Table of contents

1. [Shape of the system](#shape-of-the-system)
2. [Request lifecycle](#request-lifecycle)
3. [Authentication](#authentication)
4. [Data model](#data-model)
5. [Backend layers](#backend-layers)
6. [Domain logic in `app/Support`](#domain-logic-in-appsupport)
7. [Dashboard aggregation](#dashboard-aggregation)
8. [The AI assistant](#the-ai-assistant)
9. [Frontend architecture](#frontend-architecture)
10. [Seeding strategy](#seeding-strategy)
11. [Testing strategy](#testing-strategy)
12. [Design decisions and trade-offs](#design-decisions-and-trade-offs)

---

## Shape of the system

The application is a decoupled two tier system served from a single origin.

```
Browser
  |
  |  document request  (any path that is not /api, /sanctum, /up)
  v
routes/web.php  ->  resources/views/app.blade.php  ->  Vue 3 SPA
  |
  |  XHR, session cookie + XSRF-TOKEN header
  v
routes/api.php  ->  middleware  ->  Controller  ->  Form Request
                                        |
                                        v
                                   Eloquent model
                                        |
                                        v
                                     MySQL
                                        |
                                        v
                                   API Resource  ->  JSON
```

There is one deployable unit. Laravel serves both the SPA shell and the API, which keeps cookie based authentication simple: the SPA is same origin with the API, so there is no CORS layer and no token storage in the browser.

The catch-all route is written as a negative lookahead so the API is never shadowed:

```php
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|sanctum|up).*$');
```

---

## Request lifecycle

Take `GET /students/42` typed into the address bar.

1. **Laravel** matches the catch-all and returns the SPA shell. No data yet.
2. **Vue Router** parses the URL client side and resolves it to `StudentProfilePage`.
3. **The router guard** runs first. On a cold load the session state is unknown, so the guard awaits `fetchUser()`, which calls `GET /api/user`. A 401 sends the browser to `/login` with `?redirect=/students/42`; a 200 lets the navigation through.
4. **The page component** mounts and calls `GET /api/students/42` through the shared Axios instance in `resources/js/lib/api.js`.
5. **Laravel** routes the XHR through `auth:sanctum`, which reads the session cookie.
6. **The controller** loads the model with the relations the page needs and hands it to `StudentResource`.
7. **The resource** shapes the JSON, and the page renders.

Writes add one step: a form request validates the payload before the controller body runs, and a validation failure returns HTTP 422 with a field keyed error bag that the frontend maps back onto the form.

---

## Authentication

Sanctum is used in its **stateful SPA mode**, not its API token mode. There are no personal access tokens issued to the browser, only a normal Laravel session cookie.

The consequences are worth stating, because they explain several details elsewhere:

* The SPA must call `GET /sanctum/csrf-cookie` before its first state changing request, so that the `XSRF-TOKEN` cookie exists. Axios reads it and echoes it back as `X-XSRF-TOKEN`.
* `SANCTUM_STATEFUL_DOMAINS` must list every hostname and port the SPA is served from. `.env.example` already lists `localhost`, `localhost:5173`, `127.0.0.1`, `127.0.0.1:8000`, `::1` and `school-management.test`, which covers both the Vite dev server and the Laragon virtual host.
* Login regenerates the session id, and logout invalidates the session and regenerates the CSRF token. Both are standard session fixation defences.

Auth state lives in one place on the frontend, the `useAuth` composable, which exposes `isAuthenticated`, `isResolved` and `fetchUser`. `isResolved` exists specifically so the router guard can distinguish "not logged in" from "not asked yet", which is the difference between redirecting to the login page and waiting.

---

## Data model

```
users
  id, name, email, password, role

teachers                        school_classes
  id, name, email (unique),       id, name, level, capacity
  phone, subject
        \                              /
         \                            /
          \       lessons            /
           \--  id, title, subject  --/
                teacher_id  (null on delete)
                school_class_id  (null on delete)
                     |
                     |  1..n
                     v
                timetables
                  id, lesson_id  (cascade on delete)
                  day_of_week, start_time, end_time, room

students                         attendances
  id, name, email (unique),        id, student_id  (cascade on delete)
  birth_date, gender,              lesson_id  (nullable, null on delete)
  school_class_id                  date, status, note
    (null on delete)               unique (student_id, lesson_id, date)
       \                           index  (date, status)
        \                         /
         \-----------------------/
```

### Deletion behaviour is a design statement

Each foreign key was chosen deliberately, and the choice encodes a rule about the school:

| Relationship | On delete | Why |
| --- | --- | --- |
| `students.school_class_id` | set null | A class is dissolved; the children remain enrolled |
| `lessons.teacher_id` | set null | A teacher leaves; the lesson still exists and needs reassigning |
| `lessons.school_class_id` | set null | Same reasoning from the other side |
| `timetables.lesson_id` | cascade | A slot with no lesson is meaningless, not orphaned |
| `attendances.student_id` | cascade | Marks belong to the pupil and have no meaning without them |
| `attendances.lesson_id` | set null | The mark survives; it simply becomes a full day mark |

That last row is the subtle one. A nullable `lesson_id` means two things at once: "this absence was recorded for the whole day" and "the lesson this mark belonged to has since been deleted". Both should keep the record, so both map to the same representation.

### Indexes

* `attendances` has a unique index on `(student_id, lesson_id, date)`, which prevents marking the same pupil twice for the same lesson on the same day.
* `attendances` has a plain index on `(date, status)`, which is exactly what the at-risk query and the weekly dashboard query filter on.
* `teachers.email` and `students.email` are unique.

### The gender column

`students.gender` is a nullable single character. It was added late, in `2026_09_03_120000_add_gender_to_students_table.php`, and the migration backfills the pupils created by the seeder so that a developer who migrated before the column existed does not end up with a dashboard donut that is entirely "not recorded". Null is a real, displayed value, not an error state.

---

## Backend layers

The backend follows the standard Laravel layering, with one class per job.

### Controllers

One per resource, plus four that are not resources: `AuthController`, `DashboardController`, `SearchController` and the single action `AIChatController`.

Resource controllers stay thin. They resolve the model, apply paging and sorting, and hand off. `Controller.php` holds the shared paging, sorting and search helpers so that six controllers do not each reimplement `per_page` clamping and sort whitelisting. Whitelisting matters: a sort column arriving from a query string is interpolated into SQL, so only known columns are accepted.

### Form requests

`Store*Request` and `Update*Request` per resource. Splitting store from update is not ceremony. Update rules need the record's own id to exclude it from unique checks, and update rules are frequently `sometimes` where store rules are `required`. The rules two requests genuinely share live in `app/Http/Requests/Concerns`.

### API resources

One per model. These exist so that the JSON contract is a decision, not an accident of which columns happen to be in the table. They also control relationship loading: a resource emits a nested relation only when it was eager loaded, which is what stops list endpoints from issuing a query per row.

### Models

Thin. Relationships, casts, and constants that are genuinely domain facts rather than configuration:

* `Attendance::PRESENT`, `ABSENT`, `LATE`, and `STATUSES`. The status strings are French because they are the recorded values, and they appear in the database.
* `Attendance::AT_RISK_RATE` (15.0), `AT_RISK_MIN_RECORDS` (10) and `RECENT_DAYS` (30). The minimum record count exists so a pupil with two marks, one of them an absence, is not reported at a 50 percent absence rate.
* `Timetable::normaliseTime()`. Time columns are compared as text in the overlap query, so both sides of the comparison must be in the column's own format. Normalising in one place keeps that invariant.

---

## Domain logic in `app/Support`

Three classes hold the logic that is neither a controller concern nor a model concern.

### `TimetableConflictDetector`

Answers one question: which existing slots would a proposed slot collide with?

It fetches the overlapping slots for the day once, then tests each against three independent rules (same teacher, same room, same class) and returns every clash it finds. A single save can therefore report "the teacher is busy" and "room B12 is occupied" together, rather than surfacing one and hiding the other.

Overlap uses **half open ranges**, `[start, end)`. Two ranges overlap when each starts before the other ends. This is what makes back to back slots legal: 08:00 to 09:00 and 09:00 to 10:00 do not collide, which is the behaviour a timetable actually needs.

A null room is treated as "no room recorded", not as a shared space, so two slots without a room never clash on the room rule.

The detector is used in two places, which is why it is a class rather than a controller method: the `check-conflicts` endpoint calls it so the frontend can warn before saving, and the store and update paths call it so the rule is enforced whether or not the frontend asked.

### `Curriculum`

The Tunisian primary curriculum as weekly hours per subject per grade band, with grades 1 to 2, 3 to 4 and 5 to 6 as the three bands.

This is a single source of truth. The seeders build the timetable from it, and `CurriculumSeedTest` asserts the seeded timetable against it. If someone edits the hours table, the seeder changes and the test follows, so the data and its specification cannot drift apart.

Where the official programme quotes a half hour, the file rounds to a whole slot and documents which way it rounded and what the band total lands on. One slot is one hour, so an hours figure is also a slot count.

### `TunisianNames`

Name pools for the seeders. It exists so the demo data reads like the school it models rather than like `faker` output in the wrong language.

---

## Dashboard aggregation

`DashboardController` serves four endpoints. The split is by refresh cost and cacheability, not by convenience: the page issues all four in parallel from `Promise.all`, so a slow query on one panel does not hold up the rest of the page.

| Endpoint | Returns |
| --- | --- |
| `dashboard/stats` | Row counts, derived context, today's rate against the previous teaching day, gender split |
| `dashboard/today-schedule` | Today's slots, and whether today is a school day at all |
| `dashboard/recent-activity` | The newest records across four tables, merged |
| `dashboard/attendance-week` | Per day attendance counts for the last six teaching days |

Two ideas run through all of it.

**Every displayed figure is derived from something the school records.** The stat tile badges are an occupancy rate against declared capacity, an average per class, a count of distinct subjects, hours per class. The "Cours" tile carries no badge at all, because no honest figure about lessons adds anything to the count itself.

**Non teaching days are skipped, not zeroed.** `attendanceWeek` walks backwards through teaching days rather than calendar days, so a Sunday in the middle does not appear as a day with no attendance. Saturday is flagged as a half day and labelled as such, because it rests on far fewer marks and a reader should know that.

The frontend converts the per day counts into percentages of that day's own register. The reasoning is in the component: a day's raw total depends on how many registers happened to be taken, so a taller raw bar says "more marks were entered", not "attendance was better". As a share of its own register, each day is comparable with the others, and the two or three absences a primary school records stop vanishing under a block of present marks. The counts travel alongside the percentages so the tooltip can show both.

---

## The AI assistant

`AIChatController` is a single action controller that forwards a staff question to Google Gemini with the school as context.

### Prompt assembly

The system instruction has three parts:

1. **Rules.** Answer only from the supplied data, never invent a record, say you do not know if the answer is not present, answer in the language of the question, and quote the pre-computed totals rather than counting.
2. **`EFFECTIFS`.** Every count the model might be asked for, computed in SQL: pupils per class, unassigned pupils, and totals for pupils, teachers, classes, lessons and slots.
3. **The dataset.** Classes, teachers, pupils, lessons and the timetable, one pipe delimited line per row.

The counting block exists because of an observed failure: asked to count a list, the model would enumerate seven pupils correctly and then report six. Counting is a task SQL does perfectly and a language model does approximately, so the counting is done in SQL and the model is told to quote the result.

Plain pipe delimited text is used rather than JSON because it carries the same information in far fewer tokens.

### Robustness

Every path that can fail returns a specific French message and an appropriate status:

| Condition | Status | Behaviour |
| --- | --- | --- |
| No `GEMINI_API_KEY` | 503 | Says the key is missing from `.env` |
| Timeout, DNS or connection failure | 504 | Asks the user to retry, logs the exception |
| Upstream 429 | 502 | Says the free quota is exhausted |
| Other upstream error | 502 | Reports the status code only |
| 200 with no usable text part | 502 | Asks the user to rephrase, logs the body |

The upstream error body is deliberately not forwarded to the client, because it can echo request details back. Only the status reaches the browser; the detail goes to the log.

A successful response can still legitimately carry no text, from a safety block or from the token budget being spent before any output was produced, so that case is handled explicitly rather than dereferenced blindly.

### Boundaries

`throttle:20,1` sits on top of `auth:sanctum`. Only the last 10 turns of conversation are replayed. The upstream call times out at 15 seconds.

---

## Frontend architecture

### Composition

```
resources/js/
  app.js            Mounts the app
  router.js         Routes and the navigation guard
  lib/api.js        The single configured Axios instance
  composables/
    useAuth.js      Session state, shared across components
    useResource.js  List state: fetch, paginate, sort, search, save, delete
  components/
    AppLayout.vue     Sidebar, header, global search, the page slot
    AppModal.vue      The create and edit dialog
    ChartCanvas.vue   Chart.js wrapper with a text summary for screen readers
    MiniCalendar.vue  Month grid
    GlobalSearch.vue  Header search with a results dropdown
    FormField.vue, LoadingState.vue, EmptyState.vue,
    PaginationControls.vue, AppIcon.vue
  pages/            One component per route
```

### `useResource`

The four list pages (classes, pupils, teachers, lessons) are the same page with different columns. `useResource` holds what they share: the current page, sort column and direction, search term, loading and error state, and the create, update and delete calls. A page supplies the endpoint and its columns, and gets working pagination, sorting and search from the composable.

This is why the list pages are short. It is also the reason the timetable and attendance pages do not use it: their state is a grid and a register, not a paginated list, and forcing them through the same abstraction would have cost more than it saved.

### `ChartCanvas`

A thin wrapper over Chart.js that owns the canvas lifecycle, destroys the chart instance on unmount, and renders a `summary` string into visually hidden text. Charts are images to a screen reader, so every chart on the dashboard passes a prose summary of what it shows, built from the same data that draws it.

### Styling

Hand written CSS with custom properties, scoped per component, over a small global layer in `resources/css`. The design tokens (spacing scale, radii, shadows, type scale, the semantic colours) are global custom properties; component styles consume them.

The dashboard is the one deliberate exception to the app's single blue accent. Its stat tiles use pastel violet and amber, and its two charts run on a soft blue and yellow rather than the semantic green, amber and red used elsewhere. That exception is scoped to `.dash` and stops at the charts: every badge, pill and row on the page, the alert rates included, still uses the semantic palette, where the colour carries meaning rather than styling.

Chart colours are declared once as JavaScript constants and mirrored by `--dash-*` custom properties. Each legend swatch is bound to the same constant that colours the slice or bar it stands for, so a legend dot cannot drift away from its data.

---

## Seeding strategy

The seeders run in dependency order: users, teachers, classes, pupils, lessons, timetable, attendance.

The goal is a coherent school, not random rows. Concretely:

* Classes are real grade levels with realistic sizes.
* Teachers are drawn from subject pools, so the mathematics teachers teach mathematics. How those pools are shaped differs by band: see the staffing model below.
* Lessons connect a teacher who teaches that subject to a class at the right grade level.
* The timetable is generated from `Curriculum`, so each class receives the correct number of hours per subject for its band, and the generated slots respect the same conflict rules the application enforces at runtime.
* Attendance is seeded over the last 30 school days at 90 to 93 percent present per day, 92.1 percent overall, with a small number of pupils deliberately pushed past the 15 percent absence threshold so that the dashboard alerts panel has real content. Seven pupils clear it on the current seed.

`WithoutModelEvents` is applied on the root seeder so that model observers do not fire tens of thousands of times during the attendance seed.

That last point about attendance is what makes the demo work. A dashboard whose alerts panel is empty and whose charts are flat demonstrates nothing.

### The staffing model

A Tunisian primary school does not staff all six grades the same way, and neither does the seeder.

In the lower band (1ère and 2ème) and the mid band (3ème and 4ème), a class belongs to a **titulaire**: one main teacher who knows the children and carries most of their core programme. A second core teacher, the **adjoint**, takes the part of the core the titulaire does not, and is in turn the titulaire of a neighbouring class in the same band. That reciprocity is what keeps the model honest. Without it every class would need a second adult working six hours a week, and with it the load lands on two people who each own a class of their own. Specialists cover what is left: French from 3ème, art, sport. A titulaire holds around 60 percent of a lower-band week and 57 percent of a mid-band one, never the whole of it.

The upper band (5ème and 6ème) is staffed by **subject specialisation** instead, which is how the last two years of Tunisian primary actually run as pupils move toward collège. There is no titulaire. Every post names a subject, and the teacher holding it teaches that subject and nothing else, across several classes rather than several subjects within one. Two Arabic teachers take three classes each, three French teachers take two each, and the smaller subjects are covered by one teacher across all six. The largest single share of an upper-band class's week is the 8 hours of French, 27 percent, against the 62 percent a lower-band titulaire holds.

**Coherence is structural rather than validated.** A post in `Curriculum::ROLES` *is* a subject, so there is no arrangement of the data in which one teacher ends up with Arabic, mathematics, science and Islamic studies at once. That combination was what the titulaire model produced when it was applied to the upper band: it sat inside every hour cap and every subject-count cap, and no rule rejected it, because the rules were about quantity and the problem was about kind. Making the post the unit removes the possibility rather than checking for it afterwards. `CurriculumSeedTest` still asserts the property from the seeded rows, against a list of coherent subject groups written out in the test rather than read from `Curriculum`, so a future change that quietly merged two unrelated subjects fails the test instead of agreeing with itself.

One post covers two subjects: **Éducation Islamique and Éducation Civique** share a single teacher. They are the moral and citizenship pair, an hour each per class, they are taught together in practice, and neither is a full post on its own. Histoire-Géographie looks like a pair and is not; it is one subject in the Tunisian programme.

The band split also holds across the whole school: no teacher works in more than one band, so the person teaching mathematics to a 1ère année class is never the person teaching it to a 6ème. The upper band therefore needs 13 teachers where the titulaire model needed 12, for 29 across the school.

---

## Testing strategy

204 tests, all feature level, running against in-memory SQLite.

The suite tests **endpoints and behaviour**, not classes. There is no test that instantiates a controller directly, because a controller is not the unit that matters; the HTTP contract is. A test posts to a route, asserts the status, asserts the JSON shape, and asserts what landed in the database.

Files are organised by concern:

* Cross cutting behaviour that applies to all six resources is tested once, parameterised over the resources, in `CrudResourceTest` and `PaginationOrderingTest`, rather than repeated six times.
* Behaviour specific to one domain gets its own file: attendance rules, timetable conflicts, dashboard aggregation, search, profiles, the curriculum seed, the gender backfill, the assistant.
* The assistant tests fake the HTTP layer, so they assert prompt assembly and every failure path without ever calling Gemini or needing an API key.

Testing against SQLite while developing against MySQL is a deliberate trade-off, discussed below.

---

## Design decisions and trade-offs

### Decoupled SPA rather than Blade with Livewire

**Chosen because** the API is useful on its own, the interactions (a drag-friendly timetable grid, a bulk register, a chat page) are stateful enough that server rendered round trips would feel slow, and the separation demonstrates a REST contract that could serve a mobile client unchanged.

**Cost:** two build steps, client side routing to maintain, no server rendering, and a first paint that waits on JavaScript. For an internal administrative tool behind a login, none of that matters much.

### Session cookies rather than API tokens

**Chosen because** the SPA is same origin with the API. Cookies are then the simpler and safer option: `HttpOnly`, so a script cannot read the credential, with CSRF handled by the framework.

**Cost:** the SPA and API cannot be deployed to different origins without adding CORS and revisiting the whole approach, and `SANCTUM_STATEFUL_DOMAINS` becomes a piece of configuration that will bite anyone who forgets it. If a mobile client is ever added it would use the token mode instead, which Sanctum supports alongside this.

### Conflict detection as a service class, called from two places

**Chosen because** the frontend needs to warn before saving and the backend must enforce regardless. One class, two call sites, one rule.

**Cost:** the overlap query runs twice on a save that was pre-checked. That is cheap, and the alternative, trusting the client's pre-check, is not a real option.

### Percentages rather than counts on the weekly chart

**Chosen because** raw counts made the chart both unreadable and misleading. Bar height tracked how many registers were taken, not how attendance went.

**Cost:** the chart no longer shows volume, so a day with 400 marks and a day with 1600 look alike. The counts are in the tooltip, and the day labels flag Saturday as a half day, which is the case where volume genuinely differs by design.

Worth stating honestly: with a healthy school the percentage bars sit in a narrow band, 90 to 93 percent present across the seeded 30 day window. The variation is real but small. That is what the data says, and the alternative would be a chart that exaggerates.

### The whole dataset in the assistant prompt

**Chosen because** at a few hundred rows it fits comfortably, and it makes the assistant accurate with no retrieval infrastructure at all. Nothing can be retrieved incorrectly when everything is present.

**Cost:** it does not scale, and every request pays for the full context. This is the limitation most worth naming in a review, and it is listed in the README. The upgrade path is retrieval or function calling against the existing API.

### Counting in SQL, not in the model

**Chosen because** the model was observed miscounting lists it had just enumerated correctly. Counting is exactly what a database is for.

**Cost:** the counts block is one more thing to keep in step with the dataset block. Both are built in the same class, in adjacent methods, which is the mitigation.

### SQLite in tests, MySQL in development

**Chosen because** the suite then needs no database server, runs in seconds, and cannot damage the development data.

**Cost:** the two engines differ, and the difference is load bearing in at least one place. The attendance unique index relies on NULLs being distinct, which both engines happen to do, but that is a coincidence of agreement rather than a guarantee. A test that passes on SQLite is not proof about MySQL, and anything relying on engine specific behaviour deserves a manual check against MySQL before it ships.

### Constants over configuration

Thresholds such as the 15 percent at-risk rate, the 10 record minimum and the 30 day window are class constants, not `.env` entries.

**Chosen because** they are domain facts that belong with the code that applies them, and they are referenced from tests. A magic number in three files is a bug waiting to happen; a named constant imported in three files is not.

**Cost:** changing one requires a deployment. For a threshold that encodes a school policy and changes about never, that is the right trade.

### The scheduler orders teachers by total load, not by how many classes they serve

The timetable seeder places lessons greedily, so the order it works in decides who gets the good slots.

**Chosen because** a teacher's hours have to fit inside the 32 slots a week offers, and their total load is what says how much freedom is left: somebody owing 21 hours can be placed 11 ways, somebody owing 6 has 26. Whoever has the least slack goes first, while the grid is still empty enough to take them.

Ranking on the number of classes a teacher serves was tried first and is wrong under specialisation. It reads backwards: the upper-band French teacher covers the fewest classes, two, precisely because she owes each of them eight hours. She sorted last and five of those hours had nowhere left to go. Class count survives as the tiebreak, where it still means what it used to, that between two teachers owing the same hours the one whose hours are scattered over more timetables is the harder to place.

**Cost:** it is still a heuristic, not a solver. It is verified by outcome rather than by proof: `CurriculumSeedTest` asserts every class receives exactly its band's weekly hours, so a stranded hour fails the suite.

### Placement prefers the least used slot of the week

Within the ordering above, each hour goes to the emptiest slot in the school rather than the earliest free one.

**Chosen because** ranking on the slot index alone filled the timetable front to back. Every class took 08:00 first and 15:00 last, so the free hours every class had left over were the same two or three late afternoon slots. The teachers placed last owe one hour to each of six classes, and when all six are only free at the same moment those hours cannot all be placed. Levelling the grid scatters the leftovers across different times instead, which is what makes the last hours fit. Spreading a subject across days still outranks it, and the slot index survives as the final tiebreak so a level grid still reads front to back.

**Cost:** the resulting week looks less tidy than one packed from the top. That is the right trade, because a tidy week with an unplaced hour is not a timetable.

### French in the interface, English in the code

**Chosen because** the users are French speaking staff, and the maintainers are not necessarily the same people. Status values such as `présent` are French because they are recorded data, not code.

**Cost:** strings are hard coded in components rather than extracted into translation files, so adding a second language is a real piece of work rather than a configuration change. This is listed as a limitation in the README.
