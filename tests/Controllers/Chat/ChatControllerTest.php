<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Controllers\Chat;

use App\Models\Chat\Channel;
use App\Models\Chat\Message;
use App\Models\OAuth\Client;
use App\Models\User;
use App\Models\UserRelation;
use Faker;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    protected static $faker;

    private User $anotherUser;
    private User $user;

    public static function setUpBeforeClass(): void
    {
        self::$faker = Faker\Factory::create();
    }

    //region POST /chat/new - Create New PM

    /**
     * @dataProvider createPmWithAuthorizedGrantDataProvider
     */
    public function testCreatePmWithAuthorizedGrant($scopes, $expectedStatus)
    {
        $this->actAsScopedUser($this->user, $scopes);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus($expectedStatus);
    }

    /**
     * @dataProvider createPmWithClientCredentialsDataProvider
     */
    public function testCreatePmWithClientCredentials($scopes, $expectedStatus)
    {
        $client = Client::factory()->create(['user_id' => $this->user]);
        $this->actAsScopedUser(null, $scopes, $client);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus($expectedStatus);
    }

    /**
     * @dataProvider createPmWithClientCredentialsBotGroupDataProvider
     */
    public function testCreatePmWithClientCredentialsBotGroup($scopes, $expectedStatus)
    {
        $client = Client::factory()->create(['user_id' => $this->user]);
        $this->user->update(['group_id' => app('groups')->byIdentifier('bot')->getKey()]);
        $this->actAsScopedUser(null, $scopes, $client);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus($expectedStatus);
    }

    public function testCreatePMWhenAlreadyExists() // success
    {
        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(200);

        // should return existing conversation and not error
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(200);
    }

    public function testCreatePMWhenLeftChannel() // success
    {
        $this->actAsScopedUser($this->user, ['*']);
        $request = $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        );

        $channelId = $request->json('new_channel_id');
        $request->assertSuccessful();

        $this->json(
            'DELETE',
            route('api.chat.channels.part', [
                'channel' => $channelId,
                'user' => $this->user->user_id,
            ])
        )->assertSuccessful();

        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertSuccessful();
    }

    public function testCreatePMWhenGuest() // fail
    {
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(401);
    }

    public function testCreatePMWhenBlocked() // fail
    {
        factory(UserRelation::class)->states('block')->create([
            'user_id' => $this->anotherUser->user_id,
            'zebra_id' => $this->user->user_id,
        ]);

        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(403);
    }

    public function testCreatePMWhenRestricted() // fail
    {
        $restrictedUser = User::factory()->restricted()->create();

        $this->actAsScopedUser($restrictedUser, ['*']);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(403);
    }

    public function testCreatePMWhenSilenced() // fail
    {
        $silencedUser = User::factory()->silenced()->create();

        $this->actAsScopedUser($silencedUser, ['*']);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(403);
    }

    public function testCreatePMWhenTargetRestricted() // fail
    {
        $restrictedUser = User::factory()->restricted()->create();

        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $restrictedUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(422);
    }

    public function testCreatePMWithSelf() // fail
    {
        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->user->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(422);
    }

    public function testCreatePMWhenFriendsOnlyAndNotFriended() // fail
    {
        $privateUser = User::factory()->create(['pm_friends_only' => true]);

        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $privateUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(403);
    }

    public function testCreatePMWhenFriendsOnlyAndFriended() // success
    {
        $privateUser = User::factory()->create(['pm_friends_only' => true]);
        factory(UserRelation::class)->states('friend')->create([
            'user_id' => $privateUser->user_id,
            'zebra_id' => $this->user->user_id,
        ]);

        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $privateUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(200);
    }

    //endregion

    //region GET /chat/updates?since=[message_id] - Get Updates
    public function testChatUpdatesWhenGuest() // fail
    {
        $this->json('GET', route('api.chat.updates'))
            ->assertStatus(401);
    }

    public function testChatUpdatesWithNoNewMessages() // success
    {
        $publicChannel = Channel::factory()->type('public')->create();
        $publicMessage = Message::factory()->create(['channel_id' => $publicChannel->channel_id]);

        // join the channel
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('PUT', route('api.chat.channels.join', [
            'channel' => $publicChannel->channel_id,
            'user' => $this->user->user_id,
        ]))
            ->assertSuccessful();

        $this->json('GET', route('api.chat.updates'), ['since' => $publicMessage->message_id])
            ->assertStatus(204);
    }

    public function testChatUpdates() // success
    {
        $publicChannel = Channel::factory()->type('public')->create();
        $publicMessage = Message::factory()->create(['channel_id' => $publicChannel->channel_id]);

        // join channel
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('PUT', route('api.chat.channels.join', [
            'channel' => $publicChannel->channel_id,
            'user' => $this->user->user_id,
        ]))
            ->assertSuccessful();

        $this->json('GET', route('api.chat.updates'), ['since' => 0])
            ->assertStatus(200)
            ->assertJsonFragment(['content' => $publicMessage->content]);
    }

    public function testChatUpdatesHidesRestrictedUserMessages() // success
    {
        // create PM
        $this->actAsScopedUser($this->user, ['*']);
        $response = $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(200);

        $presenceData = $response->decodeResponseJson();
        $channelId = $presenceData['new_channel_id'];

        // create reply
        $publicMessage = Message::factory()->create([
            'user_id' => $this->anotherUser->user_id,
            'channel_id' => $channelId,
        ]);

        // ensure reply is visible
        $this->json('GET', route('api.chat.updates'), ['since' => 0])
            ->assertStatus(200)
            ->assertJsonFragment(['content' => $publicMessage->content]);

        // restrict $this->anotherUser
        $this->anotherUser->update(['user_warnings' => 1]);

        // ensure reply is no longer visible
        $this->json('GET', route('api.chat.updates'), ['since' => 0])
            ->assertStatus(204);
    }

    //endregion

    public function createPmWithAuthorizedGrantDataProvider()
    {
        return [
            [['*'], 200],
            // there's no test for bot because the test setup itself is expected to fail when setting the token.
            [['public'], 403],
        ];
    }

    public function createPmWithClientCredentialsDataProvider()
    {
        return [
            // TODO: need to add test that validates auth guard calls Token::validate
            [['public'], 403],
        ];
    }

    public function createPmWithClientCredentialsBotGroupDataProvider()
    {
        return [
            [['chat.write', 'delegate'], 200],
            [['public'], 403],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPlays()->create();
        $this->anotherUser = User::factory()->create();
    }
}
