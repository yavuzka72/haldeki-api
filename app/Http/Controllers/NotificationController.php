<?php

namespace App\Http\Controllers;

use App\Services\Push\OneSignalService;

class NotificationController extends Controller
{
    public function testPush(OneSignalService $push)
    {
        $result = $push->sendWebPush(
            'Haldeki',
            'Sunucudan gönderilen web push başarılı 🎉',
            'https://haldeki.com'
        );

        return response()->json($result);
    }
}
