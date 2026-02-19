<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\District;
use App\Http\Resources\DistrictResource;

class DistrictController extends Controller
{
    public function show($id)
    {
        $district = District::findOrFail($id);
        return new DistrictResource($district);
    }

    public function getList(Request $request)
    {
        $q = District::query();

        // CityController formatına paralel filtreler:
        $q->when($request->filled('city_id'), function ($qq) use ($request) {
            return $qq->where('city_id', $request->city_id);
        });

        $q->when($request->filled('search'), function ($qq) use ($request) {
            $s = $request->search;
            return $qq->where('name', 'LIKE', "%{$s}%");
        });

        if ($request->has('status') && isset($request->status)) {
            $q = $q->where('status', $request->status);
        }

        if ($request->has('is_deleted') && isset($request->is_deleted) && $request->is_deleted) {
            $q = $q->withTrashed();
        }

        $perPage = config('constant.PER_PAGE_LIMIT');
        if ($request->has('per_page') && !empty($request->per_page)) {
            if (is_numeric($request->per_page)) {
                $perPage = (int) $request->per_page;
            }
            if ($request->per_page == -1) {
                $perPage = $q->count();
            }
        }

        $q->orderBy('name', 'asc');
        $paginated = $q->paginate($perPage);
        $items = DistrictResource::collection($paginated);

        $response = [
            'pagination' => $this->json_pagination_response($items),
            'data'       => $items,
        ];

        return $this->json_custom_response($response);
    }

    // --- CityController ile aynı helper'lar:
    public function json_message_response($message, $status_code = 200)
    {
        return response()->json(['message' => $message], $status_code);
    }

    public function json_custom_response($response, $status_code = 200)
    {
        return response()->json($response, $status_code);
    }

    public function json_pagination_response($items)
    {
        return [
            'total_items' => $items->total(),
            'per_page'    => $items->perPage(),
            'currentPage' => $items->currentPage(),
            'totalPages'  => $items->lastPage(),
        ];
    }
}
