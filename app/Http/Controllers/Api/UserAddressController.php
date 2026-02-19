<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserAddress;
use App\Http\Resources\UserAddressResource;

class UserAddressController extends Controller
{
    
    
        public function index(Request $request)
    {
        $useraddress = UserAddress::myAddress();
        
        $useraddress->when(request('address'), function ($q) {
            return $q->where('address', 'LIKE', '%' . request('address') . '%');
        });

        $useraddress->when(request('user_id'), function ($q) {
            return $q->where('user_id', request('user_id'));
        });

        $useraddress->when(request('country_id'), function ($q) {
            return $q->where('country_id', request('country_id'));
        });

        $useraddress->when(request('city_id'), function ($q) {
            return $q->where('city_id', request('city_id'));
        });

        $per_page = config('constant.PER_PAGE_LIMIT');
        if( $request->has('per_page') && !empty($request->per_page)){
            if(is_numeric($request->per_page))
            {
                $per_page = $request->per_page;
            }
            if($request->per_page == -1 ){
                $per_page = $useraddress->count();
            }
        }

        $useraddress = $useraddress->orderBy('id','asc')->paginate($per_page);
        $items = UserAddressResource::collection($useraddress);

        $response = [
            'pagination' => json_pagination_response($items),
            'data' => $items,
        ];
        
        return json_custom_response($response);
    }




    public function getList(Request $request)
    {
        $useraddress = UserAddress::myAddress();
        
        $useraddress->when(request('address'), function ($q) {
            return $q->where('address', 'LIKE', '%' . request('address') . '%');
        });

        $useraddress->when(request('user_id'), function ($q) {
            return $q->where('user_id', request('user_id'));
        });

        $useraddress->when(request('country_id'), function ($q) {
            return $q->where('country_id', request('country_id'));
        });

        $useraddress->when(request('city_id'), function ($q) {
            return $q->where('city_id', request('city_id'));
        });

        $per_page = config('constant.PER_PAGE_LIMIT');
        if( $request->has('per_page') && !empty($request->per_page)){
            if(is_numeric($request->per_page))
            {
                $per_page = $request->per_page;
            }
            if($request->per_page == -1 ){
                $per_page = $useraddress->count();
            }
        }

        $useraddress = $useraddress->orderBy('id','asc')->paginate($per_page);
        $items = UserAddressResource::collection($useraddress);

        $response = [
            'pagination' => json_pagination_response($items),
            'data' => $items,
        ];
        
        return json_custom_response($response);
    }

    public function getDetail(Request $request)
    {
        $id = $request->id;
        $useraddress = UserAddress::where('id',$id)->withTrashed()->first();

        if(empty($useraddress))
        {
            $message = __('message.not_found_entry',[ 'name' => __('message.useraddress') ]);
            return json_message_response($message,400);   
        }
        
        $useraddress_detail = new UserAddressResource($useraddress);

        $response = [
            'data' => $useraddress_detail
        ];
        
        return json_custom_response($response);
    }
}
