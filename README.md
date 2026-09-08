# Gestion Scolaire

A school management web application for a Tunisian primary school. A Laravel REST API serves a decoupled Vue 3 single page application covering classes, pupils, teachers, lessons, the weekly timetable, daily attendance, a reporting dashboard and an in-app assistant.

The interface is in French, which is the working language of the school the application was designed around. Code, comments and this documentation are in English.

---

## Table of contents

1. [Feature tour](#feature-tour)
2. [Technology stack](#technology-stack)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Running the application](#running-the-application)
6. [Demo credentials and seeded data](#demo-credentials-and-seeded-data)
7. [Configuring the AI assistant](#configuring-the-ai-assistant)
8. [API reference](#api-reference)
9. [Testing and code style](#testing-and-code-style)
10. [Project layout](#project-layout)
11. [Known limitations](#known-limitations)

---

## Feature tour

### Dashboard

The landing page after login. It is laid out in two columns: a wide main column and a narrower right rail.

* **Six stat tiles.** Classes, pupils, teachers, lessons, timetable slots and today's attendance rate. Each tile carries a badge derived from real data (class occupancy against declared capacity, average pupils per class, the number of distinct subjects taught, hours per class per week, and the change in attendance rate against the previous teaching day). No badge is shown when there is no honest figure to put in it.
* **Gender split donut.** Boys and girls as a thin ring with a two figure icon in the centre, and a third slice for pupils whose gender was never recorded. That third slice is shown only when there is one, so the seeded data, which records every pupil, displays two slices. The legend lists each slice with its count and its share.
* **Weekly attendance bars.** The last six teaching days, charted as percentages of that day's own register rather than as raw counts, so days with different numbers of lessons stay comparable. Hovering a bar shows the underlying counts, for example `Présents : 92 % (1538/1666)`.
* **Alerts panel.** Pupils whose absence rate over the last 30 days exceeds 15 percent, with the raw absence count behind the rate and a link to the pupil profile.
* **Recent activity.** The latest records created or changed across pupils, teachers, classes and attendance.
* **Right rail.** A month calendar with today's timetable listed directly beneath it, inside a single card.

### Classes, pupils, teachers, lessons

Full create, read, update and delete for each, with server side validation, paginated and sortable listings, and a search field. Every list row links to a profile page:

* **Class profile.** Roster, the lessons attached to the class, and the class attendance rate.
* **Pupil profile.** Personal details, class, and the pupil's own attendance history and rate.
* **Teacher profile.** Subject, contact details, the lessons they teach and the classes they teach to.

Deletions are non destructive where it matters. Removing a class leaves its pupils in place with no class assigned rather than deleting them, and removing a teacher leaves their lessons in place with no teacher assigned.

### Weekly timetable

A grid of days against hourly slots, from Monday to Saturday, with Saturday running as a half day. Slots can be created, moved and deleted from the grid.

Before a slot is saved, the API checks it against every existing slot that overlaps the same time window and reports three independent kinds of clash:

* the **teacher** is already teaching elsewhere,
* the **room** is already occupied,
* the **class** is already in another lesson.

A single save can report more than one kind at once. Time ranges are treated as half open, so a lesson ending at 09:00 and one starting at 09:00 do not clash.

### Attendance

A register per class per day, optionally narrowed to one lesson. Each pupil is marked `présent`, `absent` or `retard`, with an optional note. Marks are saved in bulk in one request, and re-saving a register updates the existing marks rather than duplicating them.

A full day absence is recorded with no lesson attached, which means it survives the deletion of any individual lesson.

### AI assistant

An in-app chat page where staff can ask questions about the school in plain French or English, for example "combien d'élèves en 4ème B ?" or "who teaches mathematics to CE2 ?".

The assistant is backed by Google Gemini. On each request the backend builds a system prompt containing the full school dataset as compact plain text, plus a pre-computed block of totals. Counts are calculated in SQL before the model sees anything, and the prompt instructs the model to quote those figures rather than counting list rows itself, which is a step language models do unreliably. The model is also instructed to answer only from the supplied data and to say it does not know rather than invent a record.

The endpoint is rate limited to 20 requests per minute per user, keeps the last 10 conversation turns for context, and times out after 15 seconds. Every failure mode (missing API key, timeout, upstream error, quota exhausted, empty response) returns a specific French message rather than a blank screen.

### Global search

A single search field in the layout header queries pupils, teachers and classes at once and links straight to the matching profile.

### Authentication

Session based authentication through Laravel Sanctum in its stateful SPA mode. The Vue router guards every route except the login page, and resolves the session on a cold page load before deciding where to send the user.

---

## Technology stack

| Layer | Technology |
| --- | --- |
| Language | PHP 8.3 |
| Framework | Laravel 13 |
| Authentication | Laravel Sanctum 4 (stateful SPA sessions) |
| Database | MySQL 8 (SQLite in the test suite) |
| Frontend | Vue 3.5 with the Composition API and `<script setup>` |
| Routing | Vue Router 4 in history mode |
| HTTP client | Axios |
| Charts | Chart.js 4 |
| Build tool | Vite 8 with the Laravel Vite plugin |
| Styling | Hand written CSS with custom properties, plus Tailwind 4 |
| Testing | PHPUnit via `php artisan test` |
| Code style | Laravel Pint |
| Local environment | Laragon |

---

## Requirements

* PHP 8.3 or newer, with the usual Laravel extensions
* Composer 2
* Node.js 20 or newer, with npm
* MySQL 8 (or MariaDB), reachable on `127.0.0.1:3306`
* Optionally, a Google AI Studio API key for the assistant

---

## Installation

```bash
git clone <repository-url> school-management
cd school-management

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Create the database and point `.env` at it:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=school_management
DB_USERNAME=root
DB_PASSWORD=
```

```sql
CREATE DATABASE school_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then migrate, seed and build:

```bash
php artisan migrate --seed
npm run build
```

`composer setup` runs the install, key generation, migration and build steps in one command if you prefer.

---

## Running the application

For day to day development, one command starts the PHP server, the queue worker, the log tailer and Vite together:

```bash
composer dev
```

Or run the two halves separately:

```bash
php artisan serve     # http://127.0.0.1:8000
npm run dev           # Vite dev server with hot reload
```

Under Laragon the project is also reachable at `http://school-management.test` once the virtual host is created. That hostname is already listed in `SANCTUM_STATEFUL_DOMAINS` in `.env.example`.

For a production style run, `npm run build` compiles the assets and `php artisan serve` alone is enough.

---

## Demo credentials and seeded data

`php artisan migrate --seed` creates a single administrator account:

| Field | Value |
| --- | --- |
| Email | `admin@ecole.tn` |
| Password | `password` |

Re-running the seeder resets that password rather than failing, so it is safe to seed repeatedly.

The seeders build a plausible primary school rather than random noise:

| Table | Approximate rows |
| --- | --- |
| Classes | 16 |
| Pupils | 359 |
| Teachers | 29 |
| Lessons | 138 |
| Timetable slots | 432 |
| Attendance marks | 40,820 |

Names come from a Tunisian name list, and the timetable is generated from `app/Support/Curriculum.php`, which encodes the Tunisian primary curriculum as weekly hours per subject per grade band. One timetable slot is one hour, so those hours are also the slot counts. The seeders build from that file and the test suite asserts against it, so the two cannot drift apart.

Attendance is seeded across the last 30 school days, at a present rate between 90 and 93 percent per day and 92.1 percent overall, plus a small number of pupils pushed above the alert threshold so the dashboard alerts panel has something to show. Seven pupils clear that threshold on the current seed.

On the current seed the roll divides 204 boys to 155 girls with no pupil left unrecorded, and the school sits at 76.7 percent occupancy, 359 enrolled against 468 declared places.

The window is anchored to the day the seeder runs, so re-seed before a demo if the database has been sitting for a few days. Otherwise the dashboard chart, which shows the last six teaching days, will include days with no register.

### Which of these figures reproduce, and which do not

Half the table above is deterministic and half is not, which matters if you clone this project and find your numbers disagreeing with the documentation.

**Deterministic. Identical on every seed:** 16 classes, 29 teachers, 138 lessons and 432 timetable slots. These are derived from a fixed class list and from `app/Support/Curriculum.php`, so they are the same figures on any machine. The per class weekly hour totals are fixed too: 21 slots for grades 1 to 2, 28 for grades 3 to 4, and 30 for grades 5 to 6.

**Randomised. Different on every seed:** the pupil count, which varies with the per class roll sizes; the attendance total, which follows from it; the gender split; the at-risk cohort, including how many pupils it contains and who they are; and the exact per day attendance rates. Every specific figure of this kind quoted anywhere in this documentation describes one particular seed, taken on 8 September 2026, and is there to show the shape of the data rather than to be matched exactly.

The invariants hold on every seed regardless: no English before grade 5, no French before grade 3, each teacher confined to a single grade band, the per class hour targets met exactly, and no timetable slot conflicting with another on teacher, room or class.

To reset everything:

```bash
php artisan migrate:fresh --seed
```

---

## Configuring the AI assistant

The assistant needs a Google AI Studio key. Get one at <https://aistudio.google.com/apikey> and put it in `.env`, never in `.env.example`:

```dotenv
GEMINI_API_KEY=your-key-here
GEMINI_MODEL=gemini-3.5-flash-lite
```

The rest of the application works without a key. The assistant page will simply report that it is not configured, with an HTTP 503 and a French message explaining that `GEMINI_API_KEY` is missing.

---

## API reference

Every endpoint is prefixed with `/api`. All routes except `POST /api/login` require an authenticated session.

The SPA must request `GET /sanctum/csrf-cookie` before its first `POST` so that the `XSRF-TOKEN` cookie is present on the login request.

### Authentication

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/login` | Log in, start a session, return the user |
| `GET` | `/api/user` | The currently authenticated user |
| `POST` | `/api/logout` | Invalidate the session |

### Resources

Each of these is a standard Laravel API resource with the five usual routes (`index`, `store`, `show`, `update`, `destroy`):

| Base path | Resource |
| --- | --- |
| `/api/teachers` | Teachers |
| `/api/school-classes` | Classes |
| `/api/students` | Pupils |
| `/api/lessons` | Lessons |
| `/api/timetables` | Timetable slots |
| `/api/attendances` | Attendance marks |

Index routes accept `page`, `per_page`, `sort`, `direction` and `q` query parameters and return a paginated envelope with `data` and `meta`.

### Dashboard

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/dashboard/stats` | Headline counts, derived context, today's rate, gender split |
| `GET` | `/api/dashboard/today-schedule` | Today's timetable, and whether today is a school day |
| `GET` | `/api/dashboard/recent-activity` | Latest records created or changed |
| `GET` | `/api/dashboard/attendance-week` | Per day counts for the last six teaching days |

### Attendance

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/attendances/at-risk` | Pupils above the 15 percent absence threshold |
| `GET` | `/api/attendances/by-student/{student}` | One pupil's attendance history |
| `POST` | `/api/attendances/bulk` | Save a whole register in one request |

### Timetable

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/timetables/check-conflicts` | Teacher, room and class clashes for a proposed slot |

This route is declared before the resource routes so that `check-conflicts` is not captured by the `{timetable}` wildcard on the show route. The same applies to `attendances/at-risk`.

### Assistant

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/ai/chat` | Ask the assistant a question. Rate limited to 20 per minute |

### Other

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/search?q=` | Pupils, teachers and classes matching a term |

---

## Testing and code style

```bash
php artisan test                  # 204 tests
vendor/bin/pint --test --dirty    # style check, changed files only
vendor/bin/pint                   # apply style fixes
npm run build                     # verify the frontend compiles
```

The suite runs against an in-memory SQLite database, configured in `phpunit.xml`, so it needs no MySQL server and leaves the development database untouched.

Coverage is organised by concern rather than by class:

| File | Covers |
| --- | --- |
| `AttendanceTest.php` | Bulk saving, re-saving, full day marks, at-risk selection |
| `TimetableConflictTest.php` | Teacher, room and class clashes, half open ranges |
| `CrudResourceTest.php` | The five routes across every resource, and validation |
| `DashboardTest.php` | Every dashboard endpoint and its derived figures |
| `SearchTest.php` | Cross resource search |
| `PaginationOrderingTest.php` | Paging, sorting and sort direction |
| `ProfileEndpointTest.php` | The pupil, class and teacher profile payloads |
| `CurriculumSeedTest.php` | The seeded timetable against the curriculum table |
| `StudentGenderTest.php` | The gender column and its backfill |
| `AIChatControllerTest.php` | Prompt assembly and every assistant failure mode |
| `AuthenticationTest.php` | Sign in and out, session regeneration, the login throttle |

---

## Project layout

```
app/
  Http/
    Controllers/      One controller per resource, plus Auth, Dashboard,
                      Search and AIChat
    Requests/         Form request validation, store and update per resource
    Resources/        API resource serialisers
  Models/             Eloquent models and their relationships
  Support/
    Curriculum.php                 Tunisian primary curriculum hours per band
    TimetableConflictDetector.php  Teacher, room and class clash detection
    TunisianNames.php              Name pools used by the seeders
database/
  migrations/         Schema, in dependency order
  factories/          Model factories used by seeders and tests
  seeders/            Ordered seeders building a coherent school
resources/
  js/
    pages/            One component per route
    components/       Layout, modal, chart canvas, calendar, search, icons
    composables/      useAuth, useResource
    lib/api.js        The configured Axios instance
    router.js         Routes and the authentication guard
  css/                Application and SPA stylesheets
routes/
  api.php             Every API endpoint
  web.php             The SPA shell, everything not under /api
tests/
  Feature/            The suite
```

### Request path

A request for `/students` reaches `routes/web.php`, which returns the SPA shell for anything that is not `/api`, `/sanctum` or `/up`. Vue Router then resolves the route in the browser, the guard confirms the session, and the page component calls `/api/students` through the shared Axios instance. On the server the request passes the `auth:sanctum` middleware, reaches the controller, is validated by a form request on writes, and is serialised back through an API resource.

`ARCHITECTURE.md` covers this in more detail.

---

## Known limitations

These are deliberate boundaries of the current version rather than defects to be hidden, and each is listed with what it would take to lift it.

### Roles are stored but not enforced

The `users` table carries a `role` column that defaults to `admin`, and the seeder creates one administrator. Nothing yet distinguishes an administrator from a teacher: any authenticated user can reach every endpoint. Lifting this means adding a policy per resource and a middleware or gate check on the routes that should be restricted.

### Single school, single tenant

Everything assumes one school. There is no school identifier on any table, so the application cannot serve two schools from one installation without a schema change and a scoping layer on every query.

### No academic year or term

Classes, lessons and the timetable have no notion of a school year. Rolling into a new year means editing the existing rows rather than archiving the old year and starting a fresh one, and historical attendance cannot be filtered by term.

### The assistant sends the whole dataset on every request

The system prompt contains every pupil, teacher, class, lesson and timetable slot as plain text. That is affordable at the current scale (a few hundred rows) and it is why the assistant can answer accurately without any retrieval step, but it does not scale. A school several times this size would exceed the context window and would need retrieval, or function calling against the API, instead of a full dump.

### The assistant depends on an external service and a free tier

Without `GEMINI_API_KEY` the assistant page is unavailable, and the free tier carries a daily cap. The endpoint is throttled at 20 requests per minute to avoid burning through it accidentally, and quota exhaustion is reported to the user in plain French, but a busy day can still exhaust it.

### Attendance uniqueness is enforced in two places

The database has a unique index on `(student_id, lesson_id, date)`. Both MySQL and SQLite treat NULLs as distinct in a unique index, so that index constrains per lesson marks but does not constrain full day marks, which all carry a null `lesson_id`. Those are kept unique by `AttendanceController::bulk()` instead. The rule is therefore split between the schema and the controller, and a write that bypassed the controller could create a duplicate full day mark.

### No soft deletes and no audit trail

Deletions are permanent. The "recent activity" panel is derived from `created_at` and `updated_at` timestamps, not from a log, so it shows that a record changed but not what changed, who changed it, or anything about records that were deleted.

### Timetable slots are fixed length

One slot is one hour, which matches the curriculum this application was built around. A lesson of 90 minutes cannot be represented as a single slot.

### Attendance is not exportable

Marks can be entered and read in the interface but there is no CSV or PDF export, which is the first thing an administrator would ask for.

### No queued jobs in practice

The queue connection is configured and `composer dev` starts a worker, but nothing is currently dispatched to it. The assistant call, which is the slowest operation in the application, runs synchronously inside the request.

### The frontend has no automated tests

The test suite covers the API thoroughly and covers the frontend not at all. Component behaviour is verified by hand. Adding Vitest with Vue Test Utils would be the natural next step.

### Accessibility is partial

Charts carry text summaries for screen readers and the icons are marked decorative where appropriate, but the application has not been through a full keyboard navigation or contrast audit.

### Localisation is hard coded

The interface is French throughout, written directly into the components rather than into translation files. Supporting Arabic, which a Tunisian school would plausibly want, means extracting every string and adding right to left layout support.
