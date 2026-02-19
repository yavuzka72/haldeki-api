<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class RequestResponseLogger
{
    public function handle($request, Closure $next)
    {
        $reqId = (string) Str::uuid();
        // İstek başı log (gizli alanları maskele)
        $masked = $request->all();
        foreach (['password', 'password_confirmation'] as $k) {
            if (isset($masked[$k])) $masked[$k] = '***';
        }

        Log::debug('[REQ] order-list', [
            'rid'      => $reqId,
            'path'     => $request->path(),
            'method'   => $request->method(),
            'query'    => $request->query(),
            'body'     => $masked,
            'auth_id'  => optional($request->user('sanctum'))->id,
            'auth_type'=> optional($request->user('sanctum'))->user_type,
        ]);

        $response = $next($request);

        // Cevap sonu log
        Log::debug('[RES] order-list', [
            'rid'     => $reqId,
            'status'  => $response->getStatusCode(),
        ]);

        // İsteğe request id ekleyelim
        $response->headers->set('X-Request-Id', $reqId);
        return $response;
    }
}
