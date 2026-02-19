<?php
namespace App\Http\Controllers;


use App\Services\Push\OneSignalService;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CourierJobController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'dealer_id' => ['required','integer','exists:users,id'],
            'region'    => ['nullable','string','max:50'],
            'details'   => ['required','string','max:1000'],
        ]);

        $job = \App\Models\CourierJob::create($data);
        event(new \App\Events\CourierJobCreated($job));

        return response()->json(['ok' => true, 'id' => $job->id]);
    }
    
   public function sendJobToCouriers(Request $request, OneSignalService $push)
{
    // 1) dealer_id'yi istekte al (yoksa 24)
    $dealerId = (int) ($request->input('dealer_id', 24));

    // 2) Aday kullanıcıları çek
    $users = User::query()
        ->where('user_type', 'delivery_man')
        ->where('dealer_id', $dealerId)
        ->whereNull('deleted_at')
        ->get(['id','player_id']); // player_id varsa fallback için alıyoruz

    if ($users->isEmpty()) {
        return response()->json(['ok'=>false, 'msg'=>'No couriers found for dealer '.$dealerId], 404);
    }

    // 3) Tercih 1: external_user_id (courier_{id})
    $externalUserIds = $users->pluck('id')
        ->map(fn ($id) => "courier_{$id}")
        ->unique()
        ->values()
        ->all();

    // 4) Fallback: player_id (cihaz bazlı), boşlar hariç
    $playerIds = $users->pluck('player_id')
        ->filter()     // null/boş olanları çıkar
        ->unique()
        ->values()
        ->all();

    if (empty($externalUserIds) && empty($playerIds)) {
        return response()->json(['ok'=>false, 'msg'=>'No external_user_id or player_id available'], 422);
    }

    $title   = 'Yeni İş';
    $message = "Dealer {$dealerId} yeni teslimat atadı. Detay için tıkla.";
    $data    = ['job_id'=>123, 'deeplink'=>'haldeki://job/123'];

    $results = [];

    // --- Önce external_user_id ile dene (kullanıcı bazlı) ---
    if (!empty($externalUserIds)) {
        foreach (array_chunk($externalUserIds, 2000) as $chunk) { // OneSignal limitlerine uy
            $results[] = $push->sendMobilePush(
                externalUserIds: $chunk,
                title:  $title,
                message:$message,
                data:   $data
                // ,onlyIos:true / onlyAndroid:true  // istersen aç
            );
        }
    }

    // --- Fallback: player_id ile gönder (cihaz bazlı) ---
    // Eğer external ile recipients=0 dönüyorsa, bu bloğu zorlamak için parametre koyabilirsin.
    if (!empty($playerIds)) {
        foreach (array_chunk($playerIds, 2000) as $chunk) {
            // Universal sendPush metodu yoksa sendMobilePushForPlayers gibi ikinci bir metot yazabilirsin.
            // Aşağıdaki tek seferlik doğrudan çağrı örneği:
            $payload = [
                'app_id' => config('services.onesignal.app_id'),
                'include_player_ids' => $chunk,
                'headings' => ['tr'=>$title,'en'=>$title],
                'contents' => ['tr'=>$message,'en'=>$message],
                'data' => $data,
                'ios_sound' => 'default',
                'android_sound' => 'default',
            ];
            $res = \Http::withHeaders([
                'Authorization' => 'Key ' . config('services.onesignal.rest_api_key'),
                'Content-Type'  => 'application/json',
            ])->post(config('services.onesignal.api_url'), $payload);

            if (!$res->successful()) {
                Log::error('OneSignal player_id push error', ['status'=>$res->status(),'body'=>$res->body(),'payload'=>$payload]);
            }
            $results[] = $res->json();
        }
    }

    return response()->json([
        'ok' => true,
        'dealer_id' => $dealerId,
        'external_user_ids_sent' => count($externalUserIds),
        'player_ids_sent' => count($playerIds),
        'results' => $results,
    ]);
}
}