<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\City;
use App\Http\Resources\CityResource;

class CityController extends Controller
{
    public function show($id)
{
    $city = City::findOrFail($id);
    return new CityResource($city);
}

    public function getList(Request $request)
    {
        $city = City::query();
        
        $city->when(request('country_id'), function ($q) {
            return $q->where('country_id', request('country_id'));
        });

        $city->when(request('search'), function ($q) {
            return $q->where('name', 'LIKE', '%' . request('search') . '%')
                    ->orWhereHas('country', function ($query) {
                        $query->where('name', 'LIKE', '%' . request('search') . '%');
                    });
        });

        if( $request->has('status') && isset($request->status) )
        {
            $city = $city->where('status',request('status'));
        }

        if( $request->has('is_deleted') && isset($request->is_deleted) && $request->is_deleted){
            $city = $city->withTrashed();
        }

        $per_page = config('constant.PER_PAGE_LIMIT');
        if( $request->has('per_page') && !empty($request->per_page)){
            if(is_numeric($request->per_page))
            {
                $per_page = $request->per_page;
            }
            if($request->per_page == -1 ){
                $per_page = $city->count();
            }
        }

        $city = $city->orderBy('name','asc')->paginate($per_page);
        $items = CityResource::collection($city);

        $response = [
            'pagination' => $this->json_pagination_response($items),
            'data' => $items,
        ];
        
        return $this->json_custom_response($response);
    }

    public function getDetail(Request $request)
    {
        $id = $request->id;
        $user_id = $request->user_id;
        
         


        $city = City::where('id',$id)->withTrashed()->first();

        if(empty($city))
        {
            $message = __('message.not_found_entry',[ 'name' => __('message.city') ]);
            return $this->json_message_response($message,400);   
        }
        
        $city_detail = new CityResource($city);
    
    $city_detail->user_id = $user_id; // custom veri ekliyoruz

        $response = [
            'data' => $city_detail
        ];
        
        return $this->json_custom_response($response);
    }


public function cities(Request $request)
{
    return $this->getList($request);
}


public function json_message_response( $message, $status_code = 200)
{	
	return response()->json( [ 'message' => $message ], $status_code );
}

public function json_custom_response( $response, $status_code = 200 )
{
    return response()->json($response,$status_code);
}

public function json_list_response( $data )
{
    return response()->json(['data' => $data]);
}

public function json_pagination_response($items)
{
    return [
        'total_items' => $items->total(),
        'per_page' => $items->perPage(),
        'currentPage' => $items->currentPage(),
        'totalPages' => $items->lastPage()
    ];
}
    public function multipleDeleteRecords(Request $request)
    {
        $multi_ids = $request->ids;
        $message = __('message.msg_fail_to_delete', ['item' => __('message.city')]);

        foreach ($multi_ids as $id) {
            $city = City::withTrashed()->where('id', $id)->first();
            if ($city) {
                if($city->deleted_at != null) {
                    $city->forceDelete();
                } else {                        
                    $city->delete();
                }
                $message = __('message.msg_deleted', ['name' => __('message.city')]);
            }
        }

        return $this->json_custom_response(['message'=> $message , 'status' => true]);

    }
}
