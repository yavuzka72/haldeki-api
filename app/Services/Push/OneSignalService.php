<?php
namespace App\Services\Push;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class OneSignalService
{
    public function sendToExternalUsers(array $externalUserIds, array $contents, array $data = [], array $headings = []): array
    {
        $payload = [
            'app_id'   => config('services.onesignal.app_id'),
            'contents' => $contents,
            'headings' => $headings,
            'data'     => $data,
            'include_external_user_ids' => array_values($externalUserIds),
            'priority' => 10,
            'ios_sound' => 'default',
            'android_sound' => 'default',
        ];

        $res = Http::withHeaders([
            'Authorization' => 'Basic ' . config('services.onesignal.rest_api_key'),
            'Content-Type'  => 'application/json',
        ])->post(config('services.onesignal.api_url'), $payload);

        if (!$res->successful()) {
            Log::error('OneSignal send error', ['status' => $res->status(), 'body' => $res->body(), 'payload' => $payload]);
        }
        return $res->json();
    }
      public function sendToDealerCouriers(
        int $dealerId,                 // = client_id
        string $title,
        string $message,
        array $data = [],
        bool $onlyIos = false,
        bool $onlyAndroid = false
    ): array {
        // Dealer’a bağlı kuryeleri çek (users.dealer_id = client_id)
        $users = User::query()
            ->where('user_type', 'delivery_man')
            ->where('dealer_id', $dealerId)
            ->get(['id','player_id']);

        if ($users->isEmpty()) {
            return ['ok'=>false,'msg'=>"No couriers found for dealer {$dealerId}"];
        }

        $externalIds = $users->pluck('id')->map(fn($id) => "courier_{$id}")->unique()->values()->all();
        $playerIds   = $users->pluck('player_id')->filter()->unique()->values()->all();

        $base = [
            'app_id'        => config('services.onesignal.app_id'),
            'headings'      => ['tr'=>$title,'en'=>$title],
            'contents'      => ['tr'=>$message,'en'=>$message],
            'data'          => $data,
            'ios_sound'     => 'default',
            'android_sound' => 'default',
        ];
        if ($onlyIos)     $base['isIos']     = true;
        if ($onlyAndroid) $base['isAndroid'] = true;

        $results = [];

        // Önce external_user_ids
        if (!empty($externalIds)) {
            foreach (array_chunk($externalIds, 2000) as $chunk) {
                $payload = $base + ['include_external_user_ids' => $chunk];
                $res = Http::withHeaders([
                    'Authorization' => 'Key '.config('services.onesignal.rest_api_key'),
                    'Content-Type'  => 'application/json',
                ])->post(config('services.onesignal.api_url'), $payload);

                if (!$res->successful()) {
                    Log::error('OneSignal ext push error', ['dealer_id'=>$dealerId,'body'=>$res->body()]);
                }
                $results[] = $res->json();
            }
        }

        // Fallback: player_id (cihaz bazlı)
        if (!empty($playerIds)) {
            foreach (array_chunk($playerIds, 2000) as $chunk) {
                $payload = $base + ['include_player_ids' => $chunk];
                $res = Http::withHeaders([
                    'Authorization' => 'Key '.config('services.onesignal.rest_api_key'),
                    'Content-Type'  => 'application/json',
                ])->post(config('services.onesignal.api_url'), $payload);

                if (!$res->successful()) {
                    Log::error('OneSignal player push error', ['dealer_id'=>$dealerId,'body'=>$res->body()]);
                }
                $results[] = $res->json();
            }
        }

        return ['ok'=>true,'dealer_id'=>$dealerId,'results'=>$results];
    }
    
    
    
      public function sendWebPush(string $title, string $message, ?string $url = null, array $filters = []): array
    {
        $payload = [
            'app_id'    => config('services.onesignal.app_id'),
            'isAnyWeb'  => false, // sadece web tarayıcılarına
            'headings'  => ['tr' => $title, 'en' => $title],
            'contents'  => ['tr' => $message, 'en' => $message],
            'url'       => $url ?? 'https://haldeki.com',
            // 'web_url' => $url ?? 'https://haldeki.com', // istersen web_url kullan
            // 'chrome_web_image' => 'https://haldeki.com/assets/icons/512.png', // görsel istersen
        ];

        if (!empty($filters)) {
            $payload['filters'] = $filters;
        } else {
            $payload['included_segments'] = ['Subscribed Users'];
        }

        $res = Http::withHeaders([
            'Authorization' => 'Basic ' . config('services.onesignal.rest_api_key'),
            'Content-Type'  => 'application/json',
        ])->post(config('services.onesignal.api_url'), $payload);

        if (!$res->successful()) {
            Log::error('OneSignal Web Push Hatası', [
                'status' => $res->status(),
                'body'   => $res->body(),
                'payload'=> $payload,
            ]);
        }

        return $res->json();
    }
    
    // app/Services/Push/OneSignalService.php
public function sendMobilePush2(
    array $externalUserIds, string $title, string $message,
    array $data = [], bool $onlyIos = false, bool $onlyAndroid = false
): array {
    $payload = [
        'app_id'   => config('services.onesignal.app_id'),
        'include_external_user_ids' => array_values($externalUserIds),
        'headings' => ['tr'=>$title,'en'=>$title],
        'contents' => ['tr'=>$message,'en'=>$message],
        'data'     => $data,
        'ios_sound' => 'default',
        'android_sound' => 'default',
    ];
    if ($onlyIos)     $payload['isIos'] = true;
    if ($onlyAndroid) $payload['isAndroid'] = true;

    $res = \Http::withHeaders([
        'Authorization' => 'Key '.config('services.onesignal.rest_api_key'), // DİKKAT: Key
        'Content-Type'  => 'application/json',
    ])->post(config('services.onesignal.api_url'), $payload);

    if (!$res->successful()) \Log::error('OneSignal mobile push error', ['status'=>$res->status(),'body'=>$res->body(),'payload'=>$payload]);
    return $res->json();
}


public function sendMobilePush(
    array $externalUserIds, string $title, string $message,
    array $data = [], bool $onlyIos = false, bool $onlyAndroid = false
): array {
    // external_user_id mi, player_id mi otomatik algıla
    $isUuid = static function ($v) {
        // OneSignal player_id genelde UUID formunda (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)
        return is_string($v) && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $v);
    };

    $ids = array_values(array_unique(array_filter($externalUserIds)));
    if (empty($ids)) {
        return ['ok' => false, 'msg' => 'No target ids'];
    }

    // Eğer listedeki ID’lerin çoğu UUID ise player_id kabul et
    $uuidCount = 0;
    foreach ($ids as $id) if ($isUuid($id)) $uuidCount++;
    $treatAsPlayerIds = ($uuidCount >= ceil(count($ids) / 2));

    $base = [
        'app_id'        => config('services.onesignal.app_id'),
        'headings'      => ['tr' => $title, 'en' => $title],
        'contents'      => ['tr' => $message, 'en' => $message],
        'data'          => $data,
        'ios_sound'     => 'default',
        'android_sound' => 'default',
    ];
    if ($onlyIos)     $base['isIos']     = true;
    if ($onlyAndroid) $base['isAndroid'] = true;

    $results = [];
    foreach (array_chunk($ids, 2000) as $chunk) {
        $payload = $base + (
            $treatAsPlayerIds
                ? ['include_player_ids' => $chunk]           // cihaz bazlı
                : ['include_external_user_ids' => $chunk]    // kullanıcı bazlı
        );

        $res = \Http::withHeaders([
            'Authorization' => 'Key ' . config('services.onesignal.rest_api_key'), // DİKKAT: Key
            'Content-Type'  => 'application/json',
        ])->post(config('services.onesignal.api_url'), $payload);

        if (!$res->successful()) {
            \Log::error('OneSignal mobile push error', [
                'status'  => $res->status(),
                'body'    => $res->body(),
                'payload' => $payload,
            ]);
        }
        $results[] = $res->json();
    }

    return [
        'ok' => true,
        'mode' => $treatAsPlayerIds ? 'player_ids' : 'external_user_ids',
        'count' => count($ids),
        'results' => $results,
    ];
}

  public function sendMobilePushByDealer(
        int $dealerId,
        string $title,
        string $message,
        array $data = [],
        bool $onlyIos = false,
        bool $onlyAndroid = false
    ): array {
        // 🔹 1) Dealer’a ait kuryeleri çek
        $users = User::query()
            ->where('user_type', 'delivery_man')
            ->where('dealer_id', $dealerId)
            ->get(['id','player_id']);

        if ($users->isEmpty()) {
            Log::warning("OneSignal: Dealer {$dealerId} için kurye bulunamadı.");
            return ['ok' => false, 'msg' => "No couriers found for dealer {$dealerId}"];
        }

        // 🔹 2) external_user_id + player_id listeleri
        $externalUserIds = $users->pluck('id')->map(fn($id) => "courier_{$id}")->values()->all();
        $playerIds       = $users->pluck('player_id')->filter()->values()->all();

        // 🔹 3) Gönderim payload'ı
        $payload = [
            'app_id'   => config('services.onesignal.app_id'),
            'headings' => ['tr' => $title, 'en' => $title],
            'contents' => ['tr' => $message, 'en' => $message],
            'data'     => $data,
            'ios_sound' => 'default',
            'android_sound' => 'default',
        ];

        if ($onlyIos)     $payload['isIos'] = true;
        if ($onlyAndroid) $payload['isAndroid'] = true;

        // Öncelik: external_user_ids (login olmuş cihazlar)
        if (!empty($externalUserIds)) {
            $payload['include_external_user_ids'] = $externalUserIds;
        } elseif (!empty($playerIds)) {
            $payload['include_player_ids'] = $playerIds;
        } else {
            return ['ok'=>false,'msg'=>'No subscribed users found'];
        }

        // 🔹 4) İstek gönder
        $res = Http::withHeaders([
            'Authorization' => 'Key ' . config('services.onesignal.rest_api_key'),
            'Content-Type'  => 'application/json',
        ])->post(config('services.onesignal.api_url'), $payload);

        // 🔹 5) Hata logla
        if (!$res->successful()) {
            Log::error('OneSignal mobile push error', [
                'dealer_id' => $dealerId,
                'status'    => $res->status(),
                'body'      => $res->body(),
                'payload'   => $payload,
            ]);
        }

        return $res->json();
    }

}
