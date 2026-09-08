<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pagination has to be stable. The index endpoints order by created_at, which
 * only has second resolution, so rows written in the same second tie. Without
 * a tiebreak the database is free to return tied rows in any order, and a row
 * can then appear on two pages or on none. Every test here creates rows with
 * an identical created_at on purpose.
 */
class PaginationOrderingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Same timestamp for every row, so ordering can only be decided by the
     * orderByDesc('id') tiebreak in the controllers.
     */
    private const FROZEN_AT = '2026-09-01 08:00:00';

    private const PER_PAGE = 15;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::FROZEN_AT);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function resourceProvider(): array
    {
        return [
            'teachers' => ['teachers'],
            'school classes' => ['school-classes'],
            'students' => ['students'],
            'lessons' => ['lessons'],
            'timetables' => ['timetables'],
        ];
    }

    /**
     * Seed $count rows sharing one created_at value.
     *
     * @return list<int> the ids, in creation order
     */
    private function seedTiedRows(string $endpoint, int $count): array
    {
        $timestamps = ['created_at' => self::FROZEN_AT, 'updated_at' => self::FROZEN_AT];

        $rows = match ($endpoint) {
            'teachers' => Teacher::factory()->count($count)
                ->sequence(fn ($s) => ['email' => "teacher{$s->index}@ecole.tn"])
                ->create($timestamps),
            'school-classes' => SchoolClass::factory()->count($count)->create($timestamps),
            'students' => Student::factory()->count($count)
                ->sequence(fn ($s) => ['matricule' => "E-9{$s->index}"])
                ->create($timestamps),
            'lessons' => Lesson::factory()->count($count)->create($timestamps),
            'timetables' => Timetable::factory()->count($count)->create($timestamps),
        };

        // Every row really does share the timestamp, otherwise the test would
        // pass for the wrong reason.
        $distinct = $rows->pluck('created_at')->map(fn ($d) => (string) $d)->unique();
        $this->assertCount(1, $distinct, 'the fixture must have tied timestamps');

        return $rows->pluck('id')->all();
    }

    /**
     * @return array{ids: list<int>, meta: array<string, mixed>}
     */
    private function fetchPage(string $endpoint, int $page): array
    {
        $response = $this->getJson("/api/{$endpoint}?page={$page}")->assertOk();

        return [
            'ids' => array_column($response->json('data'), 'id'),
            'meta' => $response->json('meta'),
        ];
    }

    #[DataProvider('resourceProvider')]
    public function test_tied_timestamps_do_not_duplicate_or_drop_rows_across_pages(string $endpoint): void
    {
        $ids = $this->seedTiedRows($endpoint, 30);

        $page1 = $this->fetchPage($endpoint, 1);
        $page2 = $this->fetchPage($endpoint, 2);

        $this->assertCount(self::PER_PAGE, $page1['ids']);
        $this->assertCount(self::PER_PAGE, $page2['ids']);

        // No row on both pages.
        $this->assertSame(
            [],
            array_values(array_intersect($page1['ids'], $page2['ids'])),
            "{$endpoint}: a row appeared on both page 1 and page 2",
        );

        // No row missing from both pages.
        $seen = array_merge($page1['ids'], $page2['ids']);
        $this->assertCount(30, array_unique($seen), "{$endpoint}: duplicate ids across pages");
        $this->assertEqualsCanonicalizing(
            $ids,
            $seen,
            "{$endpoint}: paging over 2 pages did not return every row exactly once",
        );
    }

    #[DataProvider('resourceProvider')]
    public function test_tied_rows_come_back_newest_id_first(string $endpoint): void
    {
        $ids = $this->seedTiedRows($endpoint, 30);
        rsort($ids);

        $page1 = $this->fetchPage($endpoint, 1);
        $page2 = $this->fetchPage($endpoint, 2);

        // Descending id within each page, and page 1 entirely above page 2.
        $this->assertSame(array_slice($ids, 0, self::PER_PAGE), $page1['ids']);
        $this->assertSame(array_slice($ids, self::PER_PAGE), $page2['ids']);
        $this->assertGreaterThan(max($page2['ids']), min($page1['ids']));
    }

    /**
     * The same request must answer identically every time. Without the
     * tiebreak this is where a tied ordering shows up as flakiness.
     */
    #[DataProvider('resourceProvider')]
    public function test_paging_is_repeatable(string $endpoint): void
    {
        $this->seedTiedRows($endpoint, 30);

        $first = $this->fetchPage($endpoint, 1)['ids'];
        $second = $this->fetchPage($endpoint, 2)['ids'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertSame($first, $this->fetchPage($endpoint, 1)['ids'], "{$endpoint}: page 1 changed between identical requests");
            $this->assertSame($second, $this->fetchPage($endpoint, 2)['ids'], "{$endpoint}: page 2 changed between identical requests");
        }
    }

    /**
     * Walking every page must visit each row exactly once, at any page size.
     */
    #[DataProvider('resourceProvider')]
    public function test_walking_all_pages_visits_every_row_once(string $endpoint): void
    {
        $ids = $this->seedTiedRows($endpoint, 23);

        foreach ([1, 2, 7, 15] as $perPage) {
            $seen = [];
            $page = 1;

            do {
                $response = $this->getJson("/api/{$endpoint}?per_page={$perPage}&page={$page}")->assertOk();
                $seen = array_merge($seen, array_column($response->json('data'), 'id'));
                $lastPage = $response->json('meta.last_page');
                $page++;
            } while ($page <= $lastPage);

            $this->assertSame(
                count($ids),
                count(array_unique($seen)),
                "{$endpoint}: per_page={$perPage} did not surface every row exactly once",
            );
            $this->assertEqualsCanonicalizing($ids, $seen);
        }
    }

    /**
     * What the frontend's out-of-range fallback relies on: a page past the end
     * answers with an empty data array plus a meta that still reports the real
     * last_page, so the client can re-request it.
     */
    public function test_page_past_the_end_reports_the_real_last_page(): void
    {
        $this->seedTiedRows('students', 31);

        // 31 rows over 15 per page = 3 pages, the last holding one row.
        $page3 = $this->fetchPage('students', 3);
        $this->assertCount(1, $page3['ids']);
        $this->assertSame(3, $page3['meta']['last_page']);

        // Delete that row: page 3 no longer exists.
        $this->deleteJson('/api/students/'.$page3['ids'][0])->assertNoContent();

        $stale = $this->getJson('/api/students?page=3')->assertOk();
        $this->assertSame([], $stale->json('data'));
        $this->assertSame(3, $stale->json('meta.current_page'), 'current_page echoes the requested page');
        $this->assertSame(2, $stale->json('meta.last_page'), 'last_page shrank, which is the fallback signal');
        $this->assertSame(30, $stale->json('meta.total'));
        $this->assertNull($stale->json('meta.from'));
        $this->assertNull($stale->json('meta.to'));

        // Re-requesting the reported last page is a full page of rows.
        $fallback = $this->fetchPage('students', $stale->json('meta.last_page'));
        $this->assertCount(self::PER_PAGE, $fallback['ids']);
        $this->assertSame(2, $fallback['meta']['current_page']);
        $this->assertSame(16, $fallback['meta']['from']);
        $this->assertSame(30, $fallback['meta']['to']);
    }

    /**
     * Emptying a collection entirely still answers 200 with last_page 1, so the
     * fallback lands on page 1 rather than looping.
     */
    public function test_emptying_the_collection_reports_a_single_page(): void
    {
        $ids = $this->seedTiedRows('teachers', 2);

        foreach ($ids as $id) {
            $this->deleteJson("/api/teachers/{$id}")->assertNoContent();
        }

        $response = $this->getJson('/api/teachers?page=2')->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.last_page'));
    }

    /**
     * A newly created row sorts to the top of page 1 even when it shares its
     * created_at with everything else. This is what makes the frontend's
     * "jump to page 1 after a create" behaviour show the new row.
     */
    public function test_a_new_row_heads_page_one(): void
    {
        $this->seedTiedRows('students', 30);

        $created = $this->postJson('/api/students', [
            'name' => 'Nouvel Élève',
        ])->assertCreated();

        $page1 = $this->getJson('/api/students?page=1')->assertOk();

        $this->assertSame($created->json('data.id'), $page1->json('data.0.id'));
        $this->assertSame(31, $page1->json('meta.total'));
        $this->assertSame(3, $page1->json('meta.last_page'));
    }
}
