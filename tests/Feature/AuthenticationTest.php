<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The session endpoints: signing in, signing out, and the two things that
 * stand between the login form and someone guessing at it.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        // The throttle keeps its counters outside the database, so they
        // survive RefreshDatabase and would leak from one test into the next.
        RateLimiter::clear($this->throttleKey());
    }

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'admin@ecole.tn',
            'password' => Hash::make(self::PASSWORD),
            'role' => 'admin',
        ]);
    }

    /**
     * A request that looks like it came from the SPA.
     *
     * Sanctum only attaches the session middleware when the Referer or Origin
     * names a host in SANCTUM_STATEFUL_DOMAINS, which phpunit.xml pins to
     * localhost. Without this header the test client is exactly the sessionless
     * caller that test_a_sessionless_request_... covers, so the endpoints that
     * need a session have to say so explicitly.
     */
    private function fromSpa(): static
    {
        return $this->withHeader('Referer', 'http://localhost/login');
    }

    /**
     * Sign in over HTTP the way the SPA does, rather than with actingAs, so
     * the session itself is part of what is under test.
     */
    private function signIn(User $user): void
    {
        $this->fromSpa()->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    /**
     * The bucket the throttle middleware fills for this test client. It keys
     * on the resolved IP, which the test request reports as 127.0.0.1.
     */
    private function throttleKey(): string
    {
        return sha1('127.0.0.1');
    }

    public function test_a_valid_password_opens_a_session(): void
    {
        $user = $this->user();

        $this->fromSpa()->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonMissingPath('password');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused_in_french(): void
    {
        $user = $this->user();

        $this->fromSpa()->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Ces identifiants ne correspondent à aucun compte.');

        $this->assertGuest();
    }

    public function test_the_session_id_changes_on_login(): void
    {
        $user = $this->user();

        $this->fromSpa()->get('/');
        $before = session()->getId();

        $this->fromSpa()->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertNotSame(
            $before,
            session()->getId(),
            'the session must be regenerated on login, or a fixed session id survives it',
        );
    }

    public function test_logout_ends_the_session_server_side(): void
    {
        $user = $this->user();

        $this->signIn($user);
        $this->fromSpa()->getJson('/api/user')->assertOk();

        $this->fromSpa()->postJson('/api/logout')->assertNoContent();

        // The guard resolved during the logout request still holds the user in
        // memory, so the assertion that matters is what a fresh request over
        // the same session gets back: the store was invalidated, not just the
        // in-process guard forgotten.
        $this->app['auth']->forgetGuards();

        $this->fromSpa()->getJson('/api/user')->assertUnauthorized();
        $this->assertGuest();
    }

    /**
     * Five attempts a minute, then a French 429 carrying the wait.
     */
    public function test_repeated_failures_are_throttled_in_french(): void
    {
        $user = $this->user();

        $attempt = fn () => $this->fromSpa()->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $attempt()->assertStatus(422);
        }

        $response = $attempt()->assertStatus(429);

        $this->assertMatchesRegularExpression(
            '/^Trop de tentatives de connexion\. Réessayez dans \d+ secondes\.$/u',
            $response->json('message'),
        );
        $response->assertHeader('Retry-After');
    }

    /**
     * The throttle has to bite on a correct password too. Letting a valid one
     * through would turn the limit into a way of telling the two apart.
     */
    public function test_the_throttle_does_not_exempt_the_right_password(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->fromSpa()->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'not-the-password',
            ])->assertStatus(422);
        }

        $this->fromSpa()->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(429);

        $this->assertGuest();
    }

    /**
     * Without a session the endpoint refuses before it reads the credentials,
     * so a right password and a wrong one are answered identically.
     *
     * Previously the right one reached session()->regenerate() and raised a
     * 500 while the wrong one still answered 422, which let an unauthenticated
     * caller test passwords one at a time and read the answer off the status.
     */
    public function test_a_sessionless_request_cannot_be_used_to_test_passwords(): void
    {
        $user = $this->user();

        $statuses = [];

        foreach ([self::PASSWORD, 'not-the-password'] as $password) {
            RateLimiter::clear($this->throttleKey());

            // No Referer, so Sanctum treats this as coming from outside
            // SANCTUM_STATEFUL_DOMAINS and attaches no session middleware.
            $response = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => $password,
            ]);

            $statuses[] = $response->status();
        }

        $this->assertSame(
            [419, 419],
            $statuses,
            'a sessionless login must answer the same whatever the password is',
        );
        $this->assertGuest();
    }
}
