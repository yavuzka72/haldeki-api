<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Country;
use App\Http\Resources\CountryResource;

class CountryController extends Controller
{
    public function getList(Request $request)
    {
        $country = Country::query();
        
        if( $request->has('status') && isset($request->status) )
        {
            $country = $country->where('status',request('status'));
        }

        if( $request->has('code') && isset($request->code) )
        {
            $country = $country->where('code',request('code'));
        }
        
        if( $request->has('is_deleted') && isset($request->is_deleted) && $request->is_deleted){
            $country = $country->withTrashed();
        }

        $per_page = config('constant.PER_PAGE_LIMIT');
        if( $request->has('per_page') && !empty($request->per_page)){
            if(is_numeric($request->per_page))
            {
                $per_page = $request->per_page;
            }
            if($request->per_page == -1 ){
                $per_page = $country->count();
            }
        }

        $country = $country->orderBy('name','asc')->paginate($per_page);
        $items = CountryResource::collection($country);

        $response = [
            'pagination' => $this->json_pagination_response($items),
            'data' => $items,
        ];
        
        return  $this->json_custom_response($response);
    }

    public function getDetail(Request $request)
    {
        $id = $request->id;
        $country = Country::where('id',$id)->withTrashed()->first();

        if(empty($country))
        {
            $message = __('message.not_found_entry',[ 'name' => __('message.country') ]);
            return $this->json_message_response($message,400);   
        }
        
        $country_detail = new CountryResource($country);

        $response = [
            'data' => $country_detail
        ];
        
        return $this->json_custom_response($response);
    }
    public function multipleDeleteRecords(Request $request)
    {
        $multi_ids = $request->ids;
        $message = __('message.msg_fail_to_delete', ['item' => __('message.country')]);

        foreach ($multi_ids as $id) {
            $country = Country::withTrashed()->where('id',$id)->first();
            if ($country) {
                if( $country->deleted_at != null ) {
                    $country->forceDelete();
                } else {                        
                    $country->delete();
                }
                $message = __('message.msg_deleted', ['name' => __('message.country')]);
            }
        }

        return $this->json_custom_response(['message'=> $message , 'status' => true]);

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
}
