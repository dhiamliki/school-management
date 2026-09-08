<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIChatControllerTest extends TestCase
{
    use RefreshDatabase;

    private function reply(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    public function test_happy_path(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash-lite']);
        $teacher = Teacher::factory()->create(['name' => 'Salma Ben Ali', 'subject' => 'Enseignement polyvalent']);
        Timetable::factory()->for(
            Lesson::factory()->taughtBy($teacher)->create(['subject' => 'Mathématiques'])
        )->create();
        Student::factory()->count(3)->create();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->reply('Salma Ben Ali.'))]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/ai/chat', [
                'message' => 'Qui enseigne les maths ?',
                'history' => [
                    ['role' => 'user', 'content' => 'Bonjour'],
                    ['role' => 'assistant', 'content' => 'Bonjour !'],
                ],
            ])
            ->assertOk()
            ->assertExactJson(['reply' => 'Salma Ben Ali.']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('test-key', $request->header('x-goog-api-key')[0]);
            $this->assertStringContainsString('gemini-3.5-flash-lite:generateContent', $request->url());

            $system = $body['system_instruction']['parts'][0]['text'];
            // The teachers block names what a teacher actually teaches, from
            // their lessons, not the job title on the teachers table.
            $this->assertStringContainsString('Salma Ben Ali | matières: Mathématiques 1h', $system);
            $this->assertStringContainsString('PROGRAMME PAR NIVEAU', $system);
            $this->assertStringContainsString('VOLUME HORAIRE PAR CLASSE', $system);
            $this->assertStringContainsString('ENSEIGNANTS', $system);
            $this->assertStringContainsString('ÉLÈVES', $system);
            $this->assertStringContainsString('EMPLOI DU TEMPS', $system);

            // History mapped to Gemini roles, question last.
            $this->assertSame(['user', 'model', 'user'], array_column($body['contents'], 'role'));
            $this->assertSame('Qui enseigne les maths ?', $body['contents'][2]['parts'][0]['text']);

            return true;
        });
    }

    public function test_history_is_capped_at_ten_turns(): void
    {
        config(['services.gemini.key' => 'k']);
        Http::fake(['*' => Http::response($this->reply('ok'))]);

        $history = [];
        for ($i = 0; $i < 30; $i++) {
            $history[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => "turn {$i}"];
        }

        $this->actingAs(User::factory()->create())
            ->postJson('/api/ai/chat', ['message' => 'hi', 'history' => $history])
            ->assertOk();

        Http::assertSent(function ($request) {
            // 10 replayed turns + the new question.
            $this->assertCount(11, $request->data()['contents']);
            $this->assertSame('turn 20', $request->data()['contents'][0]['parts'][0]['text']);

            return true;
        });
    }

    public function test_validation(): void
    {
        config(['services.gemini.key' => 'k']);
        Http::fake();
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/ai/chat', [])->assertStatus(422);
        $this->postJson('/api/ai/chat', ['message' => str_repeat('a', 2001)])->assertStatus(422);
        $this->postJson('/api/ai/chat', [
            'message' => 'hi',
            'history' => [['role' => 'system', 'content' => 'x']],
        ])->assertStatus(422);
    }

    public function test_missing_key_is_reported_cleanly(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/ai/chat', ['message' => 'hi'])
            ->assertStatus(503)
            ->assertJsonStructure(['message']);

        Http::assertNothingSent();
    }

    public function test_upstream_failures_are_reported_cleanly(): void
    {
        config(['services.gemini.key' => 'k']);
        $this->actingAs(User::factory()->create());

        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);
        $this->postJson('/api/ai/chat', ['message' => 'hi'])
            ->assertStatus(502)
            ->assertJsonStructure(['message'])
            ->assertJsonMissing(['reply' => null]);

        Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        $this->postJson('/api/ai/chat', ['message' => 'hi'])->assertStatus(502);

        Http::fake(['*' => Http::response('boom', 500)]);
        $this->postJson('/api/ai/chat', ['message' => 'hi'])->assertStatus(502);

        // A 200 with no text part (safety block / budget spent).
        Http::fake(['*' => Http::response(['candidates' => [['finishReason' => 'SAFETY']]])]);
        $this->postJson('/api/ai/chat', ['message' => 'hi'])->assertStatus(502);
    }

    public function test_timeout_is_reported_cleanly(): void
    {
        config(['services.gemini.key' => 'k']);
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->actingAs(User::factory()->create())
            ->postJson('/api/ai/chat', ['message' => 'hi'])
            ->assertStatus(504)
            ->assertJsonStructure(['message']);
    }

    public function test_route_requires_auth_and_is_throttled(): void
    {
        config(['services.gemini.key' => 'k']);
        Http::fake(['*' => Http::response($this->reply('ok'))]);

        $this->postJson('/api/ai/chat', ['message' => 'hi'])->assertStatus(401);

        $this->actingAs(User::factory()->create());

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/ai/chat', ['message' => 'hi'])->assertOk();
        }

        $this->postJson('/api/ai/chat', ['message' => 'hi'])->assertStatus(429);
    }
}
