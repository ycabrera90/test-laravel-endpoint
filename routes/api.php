<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use GetStream\StreamChat\Client as StreamChatClient;
use \Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


Route::post('/platform/webhooks/stream-events', function (Request $request) {
    $event = $request->all();

    if (!isset($event['type']) || $event['type'] !== 'message.new') {
        return response()->json(['message' => 'Event Ignored'], 200);
    }

    $senderId = $event['user']['id'] ?? null;
    $channelId = $event['channel_id'] ?? null;
    $message = $event['message'] ?? null;
    $members = $event['members'] ?? [];

    Log::info("senderId",[$senderId]);
    Log::info("channelId",[$channelId]);
    Log::info("message",[$message]);
    Log::info("members",[$members]);

    if (!$senderId || !$channelId || !$message) {
        return response()->json(['message' => 'Invalid payload'], 400);
    }

    foreach ($members as $member) {
        $userId = $member['user_id'] ?? null;

        if (!$userId || $userId === $senderId) continue;

        dispatch(function () use ($userId, $message, $channelId) {
            sleep(4); // allow time for user to read before push

            $stream = new StreamChatClient(env('STREAM_API_KEY'),env('STREAM_API_SECRET'));
            $channel = $stream->Channel('messaging', $channelId, []);
            $channelState = $channel->query(['data' => (object) []]);
            $readState = collect($channelState['read'])->firstWhere('user.id', $userId);

            $lastRead = strtotime($readState['last_read'] ?? '1970-01-01T00:00:00Z');
            $messageTime = strtotime($message['created_at']);

            if ($lastRead < $messageTime) {

                Log::info("Sending push notification to user: $userId for channel: $channelId");

                // GET THE EXPO PUSH TOKEN FROM THE USER
                $expo_push_token = env("EXPO_PUSH_TOKEN");

                if (!$expo_push_token) return;

                Http::post('https://exp.host/--/api/v2/push/send', [
                    'to' => $expo_push_token,
                    'title' => $message['user']['name'] ?? 'Nuevo mensaje',
                    'body' => $message['text'] ?? '',
                    'data' => [
                        'type' => 'CHAT_MESSAGE',
                        'channel_id' => $channelId
                    ]
                ]);
            } else {
                Log::info("User $userId has already read the message in channel $channelId, skipping push notification.");
            }
        })->afterResponse();
    }



    return response()->json(['message' => 'Event processed successfully'], 200);
});
