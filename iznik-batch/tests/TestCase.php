<?php

namespace Tests;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Use DatabaseTransactions for serial PHPUnit execution.
    // This rolls back each test's changes, ensuring test isolation.
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Force mail driver to 'array' for testing.
        // Laravel's TestCase creates a fresh application for each test via refreshApplication().
        // Even though bootstrap.php clears the config cache, Laravel reads config from env vars
        // which in Docker include MAIL_MAILER=smtp from docker-compose.yml.
        // phpunit.xml sets MAIL_MAILER=array but only affects the process environment, not
        // the docker-compose environment variables that Laravel reads.
        config(['mail.default' => 'array']);
        \Illuminate\Support\Facades\Mail::forgetMailers();

        // View timestamp checking is now disabled in config/view.php when APP_ENV=testing.
        // This uses precompiled views unconditionally, preventing race conditions.
    }

    /**
     * Ensure tests are run via the status container, not directly.
     *
     * This prevents Claude from accidentally running tests directly with
     * `docker exec freegle-batch php artisan test` instead of via the
     * status container API which manages test isolation and reporting.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Use getenv() instead of Laravel's env() helper because the Laravel app
        // may not be fully bootstrapped yet in setUpBeforeClass().
        if (! getenv('VIA_STATUS_CONTAINER')) {
            fwrite(STDERR, "\n\n");
            fwrite(STDERR, "╔════════════════════════════════════════════════════════════════╗\n");
            fwrite(STDERR, "║  ERROR: Tests must be run via the status container!            ║\n");
            fwrite(STDERR, "║                                                                ║\n");
            fwrite(STDERR, "║  Use: curl -X POST http://localhost:8081/api/tests/php         ║\n");
            fwrite(STDERR, "║  Or:  curl -X POST http://localhost:8081/api/tests/laravel     ║\n");
            fwrite(STDERR, "║                                                                ║\n");
            fwrite(STDERR, "║  DO NOT run: docker exec freegle-batch php artisan test        ║\n");
            fwrite(STDERR, "╚════════════════════════════════════════════════════════════════╝\n");
            fwrite(STDERR, "\n");
            exit(1);
        }
    }

    /**
     * Create a test user with an email address.
     */
    protected function createTestUser(array $attributes = []): User
    {
        $user = User::create(array_merge([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ], $attributes));

        // Create email for user.
        $email = 'test'.$user->id.'@test.com';
        UserEmail::create([
            'userid' => $user->id,
            'email' => $email,
            'preferred' => 1,
            'added' => now(),
        ]);

        return $user->fresh();
    }

    /**
     * Create a test Freegle group with auto-generated unique name.
     *
     * IMPORTANT: Do NOT pass 'nameshort' or 'namefull' - these are auto-generated
     * to ensure uniqueness in parallel test runs. Use $group->nameshort in assertions.
     *
     * @throws \InvalidArgumentException if nameshort or namefull is provided
     */
    protected function createTestGroup(array $attributes = []): Group
    {
        // Fail fast if caller tries to use hardcoded names - these cause collisions in parallel tests.
        if (isset($attributes['nameshort'])) {
            throw new \InvalidArgumentException(
                "Do not pass 'nameshort' to createTestGroup() - it causes collisions in parallel tests. ".
                'Use the auto-generated name and reference $group->nameshort in assertions.'
            );
        }
        if (isset($attributes['namefull'])) {
            throw new \InvalidArgumentException(
                "Do not pass 'namefull' to createTestGroup() - it causes collisions in parallel tests. ".
                'Use the auto-generated name and reference $group->namefull in assertions.'
            );
        }

        $uniqueId = uniqid('', true);

        return Group::create(array_merge([
            'nameshort' => 'TestGroup_'.$uniqueId,
            'namefull' => 'Test Freegle Group '.$uniqueId,
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5074,
            'lng' => -0.1278,
            'onhere' => 1,
            'publish' => 1,
        ], $attributes));
    }

    /**
     * Create a membership for a user in a group.
     */
    protected function createMembership(User $user, Group $group, array $attributes = []): Membership
    {
        return Membership::create(array_merge([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ], $attributes));
    }

    /**
     * Create a test message (offer/wanted).
     */
    protected function createTestMessage(User $user, Group $group, array $attributes = []): Message
    {
        $message = Message::create(array_merge([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test Item (TestLocation)',
            'textbody' => 'This is a test offer message.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ], $attributes));

        // Create messages_groups entry.
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        return $message->fresh();
    }

    /**
     * Create multiple test messages.
     */
    protected function createTestMessages(User $user, Group $group, int $count = 3): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = $this->createTestMessage($user, $group, [
                'subject' => 'OFFER: Test Item '.($i + 1).' (TestLocation)',
                'type' => $i % 2 === 0 ? Message::TYPE_OFFER : Message::TYPE_WANTED,
            ]);
        }

        return $messages;
    }

    /**
     * Create a test chat room between two users.
     */
    protected function createTestChatRoom(User $user1, User $user2, array $attributes = []): ChatRoom
    {
        return ChatRoom::create(array_merge([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ], $attributes));
    }

    /**
     * Create a test chat message.
     */
    protected function createTestChatMessage(ChatRoom $room, User $user, array $attributes = []): ChatMessage
    {
        return ChatMessage::create(array_merge([
            'chatid' => $room->id,
            'userid' => $user->id,
            'message' => 'Test message',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ], $attributes));
    }

    /**
     * Generate a unique email address for testing.
     *
     * Use this instead of hardcoding email addresses which cause collisions in parallel tests.
     *
     * @param  string  $prefix  Optional prefix for the email (e.g., 'bounced', 'validated')
     * @param  string  $domain  Optional domain for the email (default: 'test.com')
     * @return string A unique email address
     */
    protected function uniqueEmail(string $prefix = 'test', string $domain = 'test.com'): string
    {
        return $prefix.'_'.uniqid('', true).'@'.$domain;
    }

    /**
     * Create a UserEmail record with a unique email address.
     *
     * IMPORTANT: Do NOT pass 'email' - use the returned object's email property.
     *
     * @throws \InvalidArgumentException if email is provided
     */
    protected function createTestUserEmail(User $user, array $attributes = []): UserEmail
    {
        if (isset($attributes['email'])) {
            throw new \InvalidArgumentException(
                "Do not pass 'email' to createTestUserEmail() - it causes collisions in parallel tests. ".
                'Use the auto-generated email and reference $userEmail->email in assertions.'
            );
        }

        return UserEmail::create(array_merge([
            'userid' => $user->id,
            'email' => $this->uniqueEmail(),
            'preferred' => 0,
            'added' => now(),
        ], $attributes));
    }
}
