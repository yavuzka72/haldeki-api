<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use App\Models\Setting;
use App\Models\AppSetting;

class CommonController extends Controller
{
    public function placeAutoComplete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search_text' => 'required',
            'country_code' => 'required',
            'language' => 'required'
        ]);

        if ( $validator->fails() ) {
            $data = [
                'status' => 'false',
                'message' => $validator->errors()->first(),
                'all_message' =>  $validator->errors()
            ];

            return json_custom_response($data,400);
        }
        
        $google_map_api_key = env('GOOGLE_MAP_API_KEY');
        // $response = Http::get('https://maps.googleapis.com/maps/api/place/autocomplete/json?input='.request('search_text').'&components=country:'.request('country_code').'&language:'.request('language').'&key='.$google_map_api_key);
        $response = Http::withHeaders([
            'Accept-Language' => request('language'),
        ])->get('https://maps.googleapis.com/maps/api/place/autocomplete/json?input='.request('search_text').'&components=country:'.request('country_code').'&key='.$google_map_api_key);
        return $response->json();
    }

    public function placeDetail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'placeid' => 'required',
        ]);

        if ( $validator->fails() ) {
            $data = [
                'status' => 'false',
                'message' => $validator->errors()->first(),
                'all_message' =>  $validator->errors()
            ];

            return json_custom_response($data,400);
        }
        
        $google_map_api_key = env('GOOGLE_MAP_API_KEY');
        $response = Http::get('https://maps.googleapis.com/maps/api/place/details/json?placeid='.$request->placeid.'&key='.$google_map_api_key);

        return $response->json();
    }

    public function saveSetting(Request $request)
    {
        $data = $request->all();
        foreach($data as $req) {
            $input = [
                'key'   => $req['key'],
                'type'  => $req['type'],
                'value' => $req['value'],
            ];
            Setting::updateOrCreate(['key' => $req['key'], 'type' => $req['type'] ],$input);
        }
        return json_message_response(__('message.save_form', ['form' => __('message.setting')]));
    }

    public function getSetting()
    {
        $setting = Setting::query();
        
        $setting->when(request('type'), function ($q) {
            return $q->where('type', request('type'));
        });

        $setting = $setting->get();
        $response = [
            'data' => $setting,
        ];

        return json_custom_response($response);
    }

    public function settingUploadInvoiceImage(Request $request)
    {
        $data = $request->all();
       
        $result = Setting::updateOrCreate(['key' => request('key'), 'type' => request('type')],$data);
        $collection_name = request('key');

        if(isset($request->$collection_name) && $request->$collection_name != null ) {
            $result->clearMediaCollection($collection_name);
            $result->addMediaFromRequest($collection_name)->toMediaCollection($collection_name);
        }

        $result->update([
            'value' => getSingleMedia($result, $collection_name ,null)
        ]);
        return json_message_response(__('message.save_form', ['form' => __('message.setting')]));
    }

    public function getAppSettingAndSetting(Request $request)
    {
        $setting = Setting::query();
        
        $setting->when(request('type'), function ($q) {
            return $q->where('type', request('type'));
        });

        $setting = $setting->get();

        if($request->has('id') && isset($request->id)){
            $appsetting = AppSetting::where('id',$request->id)->first();
        } else {
            $appsetting = AppSetting::first();
        }

        $response = [
            'setting_data' => $setting,
            'appsetting_data' => $appsetting,
        ];

        return json_custom_response($response);    
    }
public function distanceMatrix1(Request $request)
{
    $validator = Validator::make($request->all(), [
        'origins' => 'required',
        'destinations' => 'required',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()
        ], 400);
    }

    $google_map_api_key = env('GOOGLE_MAP_API_KEY');

    $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json', [
        'origins' => $request->origins,
        'destinations' => $request->destinations,
        'mode' => 'driving',
        'avoid' => 'tolls|ferries|highways',
        'departure_time' => 'now',
        'traffic_model' => 'best_guess',
        'key' => $google_map_api_key,
    ]);

    if ($response->successful()) {
        $data = $response->json();

        $element = $data['rows'][0]['elements'][0] ?? null;

        if ($element && $element['status'] === 'OK') {
            return response()->json([
                'status' => true,
                'distance_text' => $element['distance']['text'],
                'distance_value' => $element['distance']['value'],
                'duration_text' => $element['duration']['text'],
                'duration_value' => $element['duration']['value'],
                'origin_address' => $data['origin_addresses'][0] ?? '',
                'destination_address' => $data['destination_addresses'][0] ?? '',
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Google mesafe bilgisi alınamadı.',
            'element_status' => $element['status'] ?? 'no_element'
        ], 422);
    }

    return response()->json([
        'status' => false,
        'message' => 'Google API bağlantı hatası.',
        'error_detail' => $response->json()
    ], 500);
}


public function combinedDistanceAndRoute2(Request $request)
{
    $validator = Validator::make($request->all(), [
        'origins' => 'required|string',
        'destinations' => 'required|string',
        'vehicle_type' => 'required|in:car,motorcycle',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()->first(),
            'all_message' => $validator->errors()
        ], 400);
    }

    $google_map_api_key = env('GOOGLE_MAP_API_KEY');

    try {
        // Directions API - Alternatif rotalar dahil
        $directionsResponse = Http::get('https://maps.googleapis.com/maps/api/directions/json', [
            'origin' => $request->origins,
            'destination' => $request->destinations,
            'mode' => 'driving',
            'alternatives' => 'true', // alternatif rotalar da olsun
            'key' => $google_map_api_key,
        ]);

        $directionsData = $directionsResponse->json();

        if (!isset($directionsData['routes'])) {
            return response()->json([
                'status' => false,
                'message' => 'Google Directions verisi alınamadı.',
            ], 500);
        }

        // Ücretli geçişler için anahtar kelime ve ücret tablosu
        $fees = [
            '15 Temmuz Şehitler Köprüsü' => 20,
            'Fatih Sultan Mehmet Köprüsü' => 20,
            'Avrasya Tüneli' => 60,
            'Osmangazi Köprüsü' => 320,
            'Yavuz Sultan Selim Köprüsü' => 55,
            'Kuzey Marmara Otoyolu' => 80,
            'Otoyol AŞ' => 50,
        ];

        $bestRoute = null;
        $bestDuration = PHP_INT_MAX;

        foreach ($directionsData['routes'] as $route) {
            $steps = $route['legs'][0]['steps'] ?? [];
            $durationValue = $route['legs'][0]['duration']['value'] ?? PHP_INT_MAX;
            $containsBridgeOrToll = false;

            foreach ($steps as $step) {
                if (isset($step['html_instructions'])) {
                    foreach ($fees as $keyword => $fee) {
                        if (strpos($step['html_instructions'], $keyword) !== false) {
                            $containsBridgeOrToll = true;
                            break 2;
                        }
                    }
                }
            }

            if ($containsBridgeOrToll && $durationValue < $bestDuration) {
                $bestRoute = $route;
                $bestDuration = $durationValue;
            }
        }

        // Eğer steps içinde bulunamadıysa, warnings içinde "Tolls on route" var mı diye bakalım
        if (!$bestRoute) {
            foreach ($directionsData['routes'] as $route) {
                $durationValue = $route['legs'][0]['duration']['value'] ?? PHP_INT_MAX;
                $warnings = $route['warnings'] ?? [];

                foreach ($warnings as $warning) {
                    if (strpos($warning, 'Tolls on route') !== false) {
                        if ($durationValue < $bestDuration) {
                            $bestRoute = $route;
                            $bestDuration = $durationValue;
                        }
                    }
                }
            }
        }

        // Hala rota bulunamadıysa, en kısa süreliyi seç
        if (!$bestRoute) {
            foreach ($directionsData['routes'] as $route) {
                $durationValue = $route['legs'][0]['duration']['value'] ?? PHP_INT_MAX;
                if ($durationValue < $bestDuration) {
                    $bestRoute = $route;
                    $bestDuration = $durationValue;
                }
            }
        }

        // Son seçilen rotadan bilgiler çıkarılıyor
        $distance = $bestRoute['legs'][0]['distance']['text'] ?? '';
        $duration = $bestRoute['legs'][0]['duration']['text'] ?? '';

        $totalFee = 0;
        $crossedPoints = [];

        // Şimdi seçilen rotanın adımlarını kontrol ediyoruz
        if (isset($bestRoute['legs'][0]['steps'])) {
            foreach ($bestRoute['legs'][0]['steps'] as $step) {
                if (isset($step['html_instructions'])) {
                    foreach ($fees as $keyword => $fee) {
                        if (strpos($step['html_instructions'], $keyword) !== false) {
                            $totalFee += $fee;
                            $crossedPoints[] = $keyword;
                        }
                    }
                }
            }
        }

        // Eğer steps içinde geçiş bulunamadıysa, warnings'e bak
        if (empty($crossedPoints) && isset($bestRoute['warnings'])) {
            foreach ($bestRoute['warnings'] as $warning) {
                if (strpos($warning, 'Tolls on route') !== false) {
                    $totalFee += 100; // Sabit ücret ekliyoruz (örnek: Yavuz Sultan için 100 TL)
                    $crossedPoints[] = 'Ücretli Yol (Belirlenemedi)';
                }
            }
        }

        // Taşıt tipi motosikletse ücret yarıya düşer
        if ($request->vehicle_type == 'motorcycle') {
            $totalFee = $totalFee / 2;
        }

        return response()->json([
            'status' => true,
            'data' => [
                'distance' => $distance,
                'duration' => $duration,
                'total_fee' => round($totalFee, 2),
                'crossed_points' => $crossedPoints,
                'vehicle_type' => $request->vehicle_type,
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Hata oluştu: ' . $e->getMessage(),
        ], 500);
    }
}
public function combinedDistanceAndRoute(Request $request)
{
    $validator = Validator::make($request->all(), [
        'origins' => 'required|string',
        'destinations' => 'required|string',
        'vehicle_type' => 'required|in:car,motorcycle',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()->first(),
            'all_message' => $validator->errors()
        ], 400);
    }

    $google_map_api_key = env('GOOGLE_MAP_API_KEY');

    try {
        // Directions API'den rota çekiyoruz
        $directionsResponse = Http::get('https://maps.googleapis.com/maps/api/directions/json', [
            'origin' => $request->origins,
            'destination' => $request->destinations,
            'mode' => 'driving',
            'alternatives' => 'true', // Alternatif rotalar da olsun
            'key' => $google_map_api_key,
        ]);

        $directionsData = $directionsResponse->json();

        if (!isset($directionsData['routes'])) {
            return response()->json([
                'status' => false,
                'message' => 'Google Directions rotaları alınamadı.',
            ], 500);
        }

        // --- Köprü tanımları
        $bridges = [
            [
                'name' => '15 Temmuz Şehitler Köprüsü',
                'lat' => 41.045155,
                'lng' => 29.015932,
                'fee' => 20,
            ],
            [
                'name' => 'Fatih Sultan Mehmet Köprüsü',
                'lat' => 41.090444,
                'lng' => 29.064310,
                'fee' => 20,
            ],
            [
                'name' => 'Yavuz Sultan Selim Köprüsü',
                'lat' => 41.181547,
                'lng' => 29.074382,
                'fee' => 55,
            ],
            [
                'name' => 'Avrasya Tüneli',
                'lat' => 41.003095,
                'lng' => 29.025104,
                'fee' => 175,
            ],
        ];

        // --- En iyi rotayı seçiyoruz (en kısa süreli)
        $bestRoute = null;
        $bestDuration = PHP_INT_MAX;

        foreach ($directionsData['routes'] as $route) {
            $durationValue = $route['legs'][0]['duration']['value'] ?? PHP_INT_MAX;
            if ($durationValue < $bestDuration) {
                $bestRoute = $route;
                $bestDuration = $durationValue;
            }
        }

        if (!$bestRoute) {
            return response()->json([
                'status' => false,
                'message' => 'Uygun rota bulunamadı.',
            ], 500);
        }

        $distance = $bestRoute['legs'][0]['distance']['text'] ?? '';
        $duration = $bestRoute['legs'][0]['duration']['text'] ?? '';

        // --- Polyline çözümleme
        $overviewPolyline = $bestRoute['overview_polyline']['points'] ?? null;
        $totalFee = 0;
        $crossedPoints = [];

        if ($overviewPolyline) {
            $polylinePoints = $this->decodePolyline($overviewPolyline);

            foreach ($bridges as $bridge) {
                foreach ($polylinePoints as $point) {
                    $distanceToBridge = $this->getDistanceBetweenPoints($point['lat'], $point['lng'], $bridge['lat'], $bridge['lng']);
                    if ($distanceToBridge <= 0.9) { // 300 metre yakınlık
                        $totalFee += $bridge['fee'];
                        $crossedPoints[] = $bridge['name'];
                        break; // Aynı köprü için tekrar kontrol etme
                    }
                }
            }
        }

        // 🎯 Taşıt tipi motosikletse ➔ ücret yarıya düşürülür
        if ($request->vehicle_type == 'motorcycle') {
            $totalFee = $totalFee / 2;
        }

        return response()->json([
            'status' => true,
            'data' => [
                'distance' => $distance,
                'duration' => $duration,
                'total_fee' => round($totalFee, 2),
                'crossed_points' => $crossedPoints,
                'vehicle_type' => $request->vehicle_type,
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Hata oluştu: ' . $e->getMessage(),
        ], 500);
    }
}

// --- Polyline çözümleme (decode fonksiyonu)
private function decodePolyline($encoded)
{
    $poly = [];
    $index = $lat = $lng = 0;
    $length = strlen($encoded);

    while ($index < $length) {
        $result = 1;
        $shift = 0;
        do {
            $b = ord($encoded[$index++]) - 63 - 1;
            $result += $b << $shift;
            $shift += 5;
        } while ($b >= 0x1f);
        $lat += ($result & 1) ? ~($result >> 1) : ($result >> 1);

        $result = 1;
        $shift = 0;
        do {
            $b = ord($encoded[$index++]) - 63 - 1;
            $result += $b << $shift;
            $shift += 5;
        } while ($b >= 0x1f);
        $lng += ($result & 1) ? ~($result >> 1) : ($result >> 1);

        $poly[] = [ 'lat' => $lat * 1e-5, 'lng' => $lng * 1e-5 ];
    }

    return $poly;
}

// --- İki koordinat arasındaki mesafe hesaplama
private function getDistanceBetweenPoints($lat1, $lng1, $lat2, $lng2)
{
    $earthRadius = 6371; // km

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return $earthRadius * $c; // km cinsinden
}


    public function distanceMatrix(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'origins' => 'required',
            'destinations' => 'required',
        ]);

        if ( $validator->fails() ) {
            $data = [
                'status' => 'false',
                'message' => $validator->errors()->first(),
                'all_message' =>  $validator->errors()
            ];

            return json_custom_response($data,400);
        }
        
        $google_map_api_key = env('GOOGLE_MAP_API_KEY');
      /*  $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json?origins='.$request->origins.'&destinations='.$request->destinations.'&key='.$google_map_api_key.'&mode=driving&avoid=tolls|ferries|highways');
*/
        
        $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json', [
            'origins' => $request->origins,
            'destinations' => $request->destinations,
        //    'mode' => 'driving', // sadece araç yolu
         
          //  'departure_time' => 'now', // trafik bilgisi dahil
    //        'traffic_model' => 'best_guess', // en doğru tahmin
            'key' => $google_map_api_key,
        ]);
        return $response->json();
    }
}