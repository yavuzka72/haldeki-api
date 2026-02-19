<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHistory;
use App\Http\Resources\WalletHistoryResource;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function saveWallet(Request $request)
    {

           $current_user = auth('sanctum')->user();
    if (!$current_user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }



        $data = $request->all();
        $user_id = request()->user_id ?? auth()->user()->id;
        $data['user_id'] = $user_id;
        $currency = appSettingData('currency_code');
        if( $currency == null ) {
            return $this->json_message_response(__('message.contact_administrator'), 400);
        }
        $data['currency'] = $currency;
        $wallet =  Wallet::firstOrCreate(
            [ 'user_id' => $user_id ]
        );

        if( $data['type'] == 'credit' ) {
            $total_amount = $wallet->total_amount + $data['amount'];
        }

        if( $data['type'] == 'debit' ) {
            $total_amount = $wallet->total_amount - $data['amount'];
        }
        
        $wallet->currency = $data['currency'];
        $wallet->total_amount = $total_amount;
        $message = __('message.save_form',[ 'form' => __('message.wallet') ] );
        try
        {
            DB::beginTransaction();
            $wallet->save();
            $data['balance'] = $total_amount;
            $data['datetime'] = date('Y-m-d H:i:s');
            $result = WalletHistory::updateOrCreate(['id' => $request->id], $data);
            DB::commit();
        } catch(\Exception $e) {
            DB::rollBack();
            return $this->json_custom_response($e);
        }

        return $this->json_message_response($message);
    }
public function getList(Request $request)
{
    $current_user = auth('sanctum')->user();
    if (!$current_user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    // Eğer scopeMyWalletHistory içinde auth()->user() kullanıyorsa,
    // guard uyumsuzluğu yaşamamak için ya scope'a userId ver
    // ya da burada doğrudan user_id filtresi uygula.
    $wallet = WalletHistory::query()->where('user_id', $current_user->id);

    // Opsiyonel: admin ise user_id override edebilir (yetki kontrolünü sen ekle)
    if ($request->filled('user_id')) {
        $wallet->where('user_id', (int) $request->user_id);
    }

    $per_page = (int) ($request->per_page ?? config('constant.PER_PAGE_LIMIT', 15));
    if ($request->filled('per_page') && (int)$request->per_page === -1) {
        $per_page = (clone $wallet)->count();
    }

    $paginator = $wallet->orderByDesc('id')->paginate($per_page);
    $items     = WalletHistoryResource::collection($paginator);

    // <<< BURADA DA AYNI GUARD / DEĞİŞKENİ KULLAN >>>
    $wallet_data = Wallet::where('user_id', $current_user->id)->first();

    $response = [
        // DİKKAT: pagination için paginator nesnesini gönder
        'pagination'  => $this->json_pagination_response($paginator),
        'data'        => $items,
        'wallet_data' => $wallet_data,
    ];

    return $this->json_custom_response($response);
}
 public function balance(int $userId)
{
    $balance = \DB::table('wallets')
        ->where('user_id', $userId)
        ->sum('total_amount');

    return response()->json([
        'success' => true,
        'user_id' => $userId,
        'balance' => round($balance, 2),
    ]);
}
    
    public function getList2(Request $request)
    {

           $current_user = auth('sanctum')->user();
    if (!$current_user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }



        $wallet = WalletHistory::myWalletHistory();

        $wallet->when(request('user_id'), function ($q) {
            return $q->where('user_id', request('user_id'));
        });
        
        $per_page = config('constant.PER_PAGE_LIMIT');
        if( $request->has('per_page') && !empty($request->per_page)){
            
            if(is_numeric($request->per_page))
            {
                $per_page = $request->per_page;
            }

            if($request->per_page == -1 ){
                $per_page = $wallet->count();
            }
        }

        $wallet = $wallet->orderBy('id','desc')->paginate($per_page);
        $items = WalletHistoryResource::collection($wallet);

        $wallet_data = Wallet::where('user_id', auth()->user()->id)->first();
        $response = [
            'pagination' => $this->json_pagination_response($items),
            'data' => $items,
            'wallet_data' => $wallet_data
        ];
        
        return $this->json_custom_response($response);
    }

    public function getWallatDetail(Request $request)
    {

           $current_user = auth('sanctum')->user();
    if (!$current_user) {
        return $this->response()->json(['message' => 'Unauthenticated'], 401);
    }

        $wallet_data = Wallet::where('user_id', auth()->id())->first();

        if( $wallet_data == null ) {
            $message = __('message.not_found_entry',[ 'name' => __('message.wallet')]);
            return $this->json_message_response($message,400);
        }
        
        $response = [
            'wallet_data' => $wallet_data ?? null,
            'total_amount'  => $wallet_data->total_amount,
        ];
        
        return $this->json_custom_response($response);
    }


    function json_message_response( $message, $status_code = 200)
{	
	return response()->json( [ 'message' => $message ], $status_code );
}

function json_custom_response( $response, $status_code = 200 )
{
    return response()->json($response,$status_code);
}

function json_list_response( $data )
{
    return response()->json(['data' => $data]);
}

function json_pagination_response($items)
{
    return [
        'total_items' => $items->total(),
        'per_page' => $items->perPage(),
        'currentPage' => $items->currentPage(),
        'totalPages' => $items->lastPage()
    ];
}
}