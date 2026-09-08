# Demo script

A walkthrough of Gestion Scolaire for a live presentation. Target length is **10 to 12 minutes**, with a 5 minute short version marked below.

Sections marked **[SHORT]** are the cut-down version. Everything else is for the full walkthrough.

---

## Table of contents

1. [Before you present](#before-you-present)
2. [Opening, 45 seconds](#1-opening-45-seconds)
3. [Login, 30 seconds](#2-login-30-seconds)
4. [Dashboard, 2 minutes](#3-dashboard-2-minutes)
5. [Classes and profiles, 90 seconds](#4-classes-and-profiles-90-seconds)
6. [Pupils, CRUD and validation, 90 seconds](#5-pupils-crud-and-validation-90-seconds)
7. [Timetable and conflict detection, 2 minutes](#6-timetable-and-conflict-detection-2-minutes)
8. [Attendance, 90 seconds](#7-attendance-90-seconds)
9. [AI assistant, 2 minutes](#8-ai-assistant-2-minutes)
10. [Code and tests, 90 seconds](#9-code-and-tests-90-seconds)
11. [Closing, 45 seconds](#10-closing-45-seconds)
12. [Anticipated questions](#anticipated-questions)
13. [If something breaks](#if-something-breaks)

---

## Before you present

Run through this **15 minutes before**, not 2 minutes before.

### Reset to a clean, known state

```bash
php artisan migrate:fresh --seed
npm run build
php artisan serve
```

Seeding takes a few seconds and rebuilds around 40,000 attendance marks, so do not leave it until the last moment.

**Do not skip the re-seed.** Attendance is seeded across the last 30 school days counting back from the day the seeder runs. If the database was last seeded even a few days ago, the dashboard chart, which shows the last six teaching days, will contain days with no register and will render partly empty.

**Freshness baseline.** This database was last seeded on **8 September 2026**, with attendance running from 5 August to 8 September and all six bars of the weekly chart populated. Every specific figure quoted in this script comes from that seed. If you are presenting later than a day or two after that date, re-seed and then re-read the section below, because the pupil count, the gender split and the at-risk names all come from randomised factories and will have changed. The structural figures, sixteen classes, twenty-nine teachers, a hundred and thirty-eight lessons and four hundred and thirty-two slots, are deterministic and will not.

### Checklist

* [ ] MySQL is running. Under Laragon, confirm the MySQL light is green. This is the single most common failure.
* [ ] `php artisan serve` is up, or the Laragon virtual host resolves.
* [ ] `GEMINI_API_KEY` is set in `.env`. Test it now by opening the assistant page and asking one question. Do not discover a dead key on stage.
* [ ] Log in once in the browser you will present from, then log out again, so the session and asset caches are warm.
* [ ] Browser zoom at 100 percent, or 110 percent for a projector. Close every unrelated tab.
* [ ] Have a second terminal open at the project root, ready for `php artisan test`.
* [ ] Note today's day of the week. If you are presenting on a **Sunday**, the dashboard schedule panel and the attendance page will both correctly report that the school is closed. Say so in advance rather than looking surprised.

### Have these ready to paste

| Purpose | Value |
| --- | --- |
| Login email | `admin@ecole.tn` |
| Login password | `password` |
| Assistant question | `Combien y a-t-il d'élèves par classe ?` |
| Follow-up question | `Et qui enseigne les mathématiques ?` |

---

## 1. Opening, 45 seconds

**[SHORT]**

Do not open the browser yet. Say what the thing is first.

> "This is Gestion Scolaire, a school management application for a Tunisian primary school. It handles the six things such a school actually runs on: classes, pupils, teachers, lessons, the weekly timetable, and daily attendance. On top of that there is a reporting dashboard and an assistant that answers questions about the school in natural language.
>
> Technically it is two pieces. A Laravel REST API on the back, and a Vue 3 single page application on the front, talking to it over JSON. The interface is in French because that is the working language of the school; the code is in English.
>
> The data you are about to see is seeded, but it is not random. It is built from the actual Tunisian primary curriculum, so the timetables and the class sizes are the ones a real school would have."

That last sentence is worth saying. It preempts "is this just faker output?"

---

## 2. Login, 30 seconds

**[SHORT]**

Open the app at the root. You land on `/login`, because the router guard redirected you.

Log in as `admin@ecole.tn` / `password`.

> "Authentication is session based, through Laravel Sanctum in its SPA mode. There is no token sitting in local storage; it is an HttpOnly cookie, so a script on the page cannot read the credential. The Vue router guards every route, and on a cold page load it resolves the session before deciding where to send you, which is why deep linking to a page while logged out sends you here and then back to where you were going."

If you want to demonstrate that, log out, paste `/students/12` into the address bar, and log in. You land on the pupil, not the dashboard. It is a five second detour and it lands well.

---

## 3. Dashboard, 2 minutes

**[SHORT]** (cut to 45 seconds: the tiles and the bar chart only)

You are on `/dashboard`. Do not rush. This page carries most of the reasoning in the project.

### The tiles, 30 seconds

> "Six headline figures across the top. The point I want to make is about the badges on each tile. Every one of them is a real derived figure: class occupancy against the declared capacity, the average pupils per class, the number of distinct subjects taught, hours per class per week."

Point at the **Cours** tile.

> "That one deliberately has no badge. There is no honest figure about lessons that adds anything to the count itself, so there is nothing there. Putting a fake 'plus twelve percent' in that corner is exactly what this page should not do."

### The attendance tile, 15 seconds

> "Today's attendance rate, with the change against the previous teaching day. Previous teaching day, not yesterday, so a Monday compares against Saturday, not against a Sunday with no register."

### The donut, 20 seconds

> "A hundred and ninety-four boys, a hundred and sixty-seven girls. There is a third slice for pupils whose gender was never recorded, but it only appears when there is one, and the seeder derives gender from the first name, so every pupil here has it. Null is a real category rather than something silently sorted onto one side. The legend gives the count and the share for each slice."

### The bar chart, 45 seconds

This is the strongest technical story on the page. Give it the time.

> "Attendance over the last six teaching days. The important decision here is that these are percentages, not raw counts.
>
> With raw counts, the bar height tracked how many registers happened to be taken that day, not how attendance actually went. A day with more lessons scheduled produced a taller bar and looked like a better day. It was accurate and completely misleading."

Hover a bar. The tooltip appears.

> "As a share of each day's own register, the six days are genuinely comparable. And the counts are not lost: hover and you get both, ninety-two percent from fifteen thirty-eight out of sixteen sixty-six."

Be honest about what it shows:

> "You will notice the bars sit in a narrow band, ninety to ninety-three percent, averaging just under ninety-two. That is what a healthy school looks like. I would rather show a chart that is boring and true than one that exaggerates."

That admission consistently reads as strength, not weakness. Say it.

### The alerts panel, 20 seconds

> "Seven pupils are above a fifteen percent absence rate over the last thirty days, with the raw counts behind each rate so you can check them. Top of the list is Malek Zaidi in 5ème année B, at just under twenty-nine percent, thirty-four absences out of a hundred and eighteen marks. There is a second condition that is not visible: a pupil needs at least ten records before they can appear. Without that, a pupil with two marks and one absence shows up at a fifty percent absence rate, which is noise, not a signal."

### The right rail, 10 seconds

> "Calendar and today's timetable, together in one card, because they are the same question."

---

## 4. Classes and profiles, 90 seconds

Go to **Classes**.

> "Sixteen classes across the six primary grades, three hundred and fifty-nine pupils, twenty-nine teachers. Sortable columns, paginated, searchable, and the list pages all share one composable on the frontend, so pagination and sorting are written once rather than four times."

Click into a class.

> "The class profile: the roster, the lessons attached to the class, and the class attendance rate."

Click through to a pupil from the roster, then to the pupil's teacher.

> "Everything is linked. Class, pupil, teacher, and back."

### The deletion story, 30 seconds

Worth telling, without actually deleting anything:

> "The foreign keys encode school rules rather than just referential integrity. Delete a class and its pupils stay enrolled with no class assigned, because dissolving a class does not unenrol children. Delete a teacher and their lessons remain, needing reassignment. But delete a lesson and its timetable slots cascade away, because a slot with no lesson is meaningless.
>
> Attendance is the interesting one. A mark keeps its pupil by cascade, but loses its lesson to a null. A null lesson already means 'recorded for the whole day', so a mark whose lesson was deleted degrades into a full day mark instead of disappearing."

---

## 5. Pupils, CRUD and validation, 90 seconds

**[SHORT]** (30 seconds: create one pupil, show the validation error, move on)

Go to **Élèves**. Use the search field.

> "Search, sort, paginate. The sort column arrives from the query string and goes into SQL, so it is whitelisted against known columns rather than interpolated."

Click **add**. Fill in a pupil, but **deliberately reuse an existing email**, and save.

> "Validation runs server side, in a form request, and comes back as a 422 with a field keyed error bag that the form maps back onto the right input. The frontend does not decide what is valid; it only displays what the server decided."

Fix the email, save, and show the new pupil in the list. Then edit and delete them to leave the demo data clean.

---

## 6. Timetable and conflict detection, 2 minutes

**[SHORT]** (60 seconds: show the grid, then trigger one conflict)

Go to **Emploi du temps**. This is the best feature in the project. Do not undersell it.

> "Monday to Saturday against hourly slots. Saturday is a half day, which is how Tunisian primary schools actually run. Four hundred and thirty-two slots covering a hundred and thirty-eight lessons across sixteen classes, all generated from the curriculum file."

### Trigger a conflict, 60 seconds

Start creating a slot that puts a **teacher who is already teaching** into an overlapping time.

The warning appears live, before you save.

> "That check runs as you type, against a dedicated endpoint. Three separate things cannot be in two places at once: the teacher, the room, and the class. Each is checked independently, so one save can report more than one clash at once rather than showing you the first and hiding the rest."

If you can, set up a slot that trips **two** rules at once. It is the most convincing version of the demo.

> "One more detail worth mentioning. Overlaps use half open ranges. A lesson ending at nine and a lesson starting at nine do not collide, which is what a timetable actually needs.
>
> And the same class does the checking on save, not just on the live preview. The frontend warning is a courtesy; the backend enforcement is the rule. A request that skipped the UI entirely still cannot create a clash."

---

## 7. Attendance, 90 seconds

**[SHORT]** (skip entirely if short on time; the dashboard already showed the output)

Go to **Présences**.

Pick a class, leave the lesson as **Journée entière**.

> "The register for one class on one day. Whole day, or narrowed to a single lesson."

Click **Tout présent**, then flip two or three pupils to absent or late.

> "Present, absent, late, with an optional note."

Save.

> "The whole register saves in one request rather than one per pupil, and saving again updates the existing marks instead of duplicating them. There is a unique index on pupil, lesson and date backing that up.
>
> With one honest caveat: both MySQL and SQLite treat nulls as distinct in a unique index, so that index constrains the per lesson marks but not the full day ones, which all carry a null lesson. Those are kept unique in the controller instead. The rule is split across two places, and I would rather tell you that than let you find it."

Volunteering a known weakness before you are asked is a strong move in front of engineers. Keep it to one sentence and move on.

---

## 8. AI assistant, 2 minutes

**[SHORT]** (60 seconds: one question, then the counting explanation)

Go to **Assistant IA**.

> "Staff can ask about the school in plain language. It is backed by Google Gemini."

Click the suggestion **Combien y a-t-il d'élèves par classe ?**

Wait for the answer. Then ask the follow-up: **Et qui enseigne les mathématiques ?**

> "Note that it handled 'and who teaches maths', without me repeating the context. The last ten turns of the conversation go up with each request."

### The part that matters, 60 seconds

> "How it works is more interesting than that it works. There is no vector database and no retrieval step. On every request the backend serialises the entire school into compact plain text, a few hundred rows, and puts it in the system prompt. At this scale it fits, and nothing can be retrieved incorrectly when everything is present.
>
> But the counts are handled separately, and that is the part I would point to."

Pull up `AIChatController::countsContext()` if you have the editor to hand.

> "Asked to count a list, the model would enumerate seven pupils correctly and then report six. Counting is something SQL does perfectly and a language model does approximately. So every number it might be asked for is computed in SQL first, put in the prompt as fact, and the model is instructed to quote those figures and never to count list rows itself.
>
> It is also told to answer only from the supplied data and to say it does not know rather than invent a pupil."

Try that if you have the time. Ask about a pupil who does not exist.

> "The failure paths are all handled explicitly. No API key, timeout, upstream error, quota exhausted, or a successful response that carries no text, which happens on a safety block. Each returns a specific French message rather than a blank screen. And the upstream error body never reaches the browser, because it can echo request details back; only the status does."

### Name the limitation yourself

> "The obvious limitation: sending the whole dataset does not scale. At a few hundred rows it is comfortable. At a few thousand it breaks, and the fix is retrieval or function calling against the API that already exists. It is in the README under known limitations."

---

## 9. Code and tests, 90 seconds

**[SHORT]** (30 seconds: run the tests, show the count, stop)

Switch to the terminal.

```bash
php artisan test
```

While it runs, talk:

> "A hundred and eighty-six tests, all at the feature level, against in-memory SQLite. They test endpoints, not classes. Nothing instantiates a controller directly, because the controller is not what matters; the HTTP contract is. Each test posts to a route, checks the status, checks the JSON shape, and checks what actually landed in the database."

When it comes back green:

> "The cross cutting behaviour, the five CRUD routes, pagination, sorting, validation, is tested once and parameterised over all six resources rather than copied six times. The domain specific rules get their own files: attendance, timetable conflicts, dashboard aggregation, the curriculum seed.
>
> The assistant tests fake the HTTP layer, so every failure path is covered without ever calling Gemini or needing a key in CI."

If you have another 20 seconds, open `app/Support/Curriculum.php`.

> "This is the curriculum as weekly hours per subject per grade band. It is the single source of truth: the seeders build the timetable from it, and a test asserts the seeded timetable back against it. The data and its specification cannot drift apart."

---

## 10. Closing, 45 seconds

**[SHORT]**

> "So: a Laravel API and a Vue SPA covering the six things a primary school runs on, with a dashboard that only shows figures it can defend, timetable conflict detection enforced on both sides, and an assistant that is careful about the one thing language models are unreliable at.
>
> There is a known limitations section in the README with a dozen entries, each with what it would take to fix. The three I would tackle first are role enforcement, since roles are stored but every authenticated user currently reaches every endpoint; an academic year concept, since nothing right now can be archived and rolled over; and attendance export, which is the first thing an administrator would ask for.
>
> Happy to go deeper on any part of it."

Ending on what you would do next, unprompted, is better than ending on a feature list.

---

## Anticipated questions

**"Why a separate SPA and not Blade?"**
> The API is useful independently, the interactions are stateful enough that server round trips would feel slow, and the same REST contract would serve a mobile client unchanged. The cost is two build steps and no server rendering, which does not matter much for an internal tool behind a login.

**"Why cookies rather than JWT?"**
> The SPA is same origin with the API, so cookies are simpler and safer here. HttpOnly means a script cannot read the credential, and the framework handles CSRF. If a mobile client were added it would use Sanctum's token mode alongside this, not instead of it.

**"How do you know the seeded data is realistic?"**
> The timetable is generated from the actual Tunisian primary curriculum, encoded as weekly hours per subject per grade band, and a test asserts the seeded result against that table. Class sizes and the subject to teacher mapping follow from it.

**"What happens if Gemini is down?"**
> A 504 with a French message asking the user to retry, and the exception goes to the log. Every other failure path returns its own specific message. The rest of the application is unaffected; nothing else depends on that service.

**"Is there any authorisation?"**
> Authentication yes, authorisation no. The users table has a role column that defaults to admin, but nothing enforces it, so any authenticated user reaches every endpoint. It is the first item in the known limitations. Lifting it means a policy per resource and gate checks on the restricted routes.

**"How would you handle a school ten times this size?"**
> Three things break in order. The assistant prompt goes first, and needs retrieval rather than a full dump. Then the dashboard aggregates, which would need caching or summary tables rather than counting live. The CRUD paths are already paginated and indexed, so they hold longest.

**"Why is the interface French but the code English?"**
> The users are French speaking school staff. The maintainers are not necessarily the same people. The status values such as `présent` are French because they are recorded data, not code.

**"Did you write the CSS by hand?"**
> Yes, hand written CSS with custom properties for the design tokens, scoped per component. Tailwind is present through the build but the layout and components are hand written.

**"What was the hardest part?"**
> The timetable conflict detection, because getting the rules right mattered more than getting them working. Half open ranges so back to back lessons do not collide, a null room meaning "no room recorded" rather than a shared space, and reporting every clash rather than the first one. And the same class enforcing it on save, not just previewing it.

---

## If something breaks

| Symptom | Cause and recovery |
| --- | --- |
| Blank page, console shows failed XHRs | MySQL is not running. Start it in Laragon and reload. |
| Login returns 419 | Stale CSRF token. Hard reload with Ctrl+Shift+R, then log in again. |
| Login returns 401 with correct credentials | Seeder did not run, or the password was changed. Run `php artisan db:seed --class=UserSeeder`, which resets it. |
| Assistant returns 503 | `GEMINI_API_KEY` is missing from `.env`. Say so, note that the rest of the app is unaffected, and describe the feature instead of showing it. |
| Assistant returns 502 mentioning quota | Free tier exhausted. Same recovery: describe it, move on. This is a good moment to mention that the endpoint is throttled precisely to avoid this. |
| Assistant is slow | It times out at 15 seconds. Keep talking through the prompt assembly while it thinks. |
| Dashboard schedule says the school is closed | You are presenting on a Sunday. Correct behaviour. Say so. |
| Styles look wrong or unstyled | The build is stale. `npm run build` and hard reload. |
| A page 404s on refresh | You are on a server without the SPA catch-all. Use `php artisan serve` rather than opening files directly. |

### The general rule

If a feature will not run, describe it and move on. Do not debug live. You have a finite window and a working feature you skipped is far cheaper than five minutes of silence in front of a terminal.

Keep a browser tab open on the README's known limitations section. If a question goes somewhere you cannot demonstrate, that section is a good place to land.
