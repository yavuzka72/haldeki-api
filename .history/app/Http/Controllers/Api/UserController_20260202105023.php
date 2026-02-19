<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Requests\UserRequest;
 
use Validator;
use Hash;
Use Auth;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Password;
use App\Models\Country;
use App\Models\City;
use App\Models\Order;
use App\Http\Resources\OrderResource;
use Carbon\Carbon;
use App\Models\AppSetting;
use App\Models\DeliveryManDocument;
use App\Models\Payment;
use App\Models\UserBankAccount;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\UserDetailResource;
use App\Http\Resources\WalletHistoryResource;
use App\Http\Resources\DeliveryManEarningResource;
use App\Http\Resources\PaymentResource;
use App\Http\Requests\UserUpdateRequest;
use App\Notifications\EmailVerification;
use App\Models\VerificationCode;
 use App\Http\Resources\DeliveryManOrderResource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

  
use Illuminate\Validation\ValidationException;



use function App\Helper\helper\getSingleMedia; // <-- önemli


class UserController extends Controller
{
 
    public function dashboard(Request $request)
    {
        $dashboard_data = [];
        $dashboard_data['total_country'] = Country::count();
        $dashboard_data['total_city'] = City::count();
        $dashboard_data['total_client'] = User::userCount('client');
        $dashboard_data['total_delivery_man'] = User::userCount('delivery_man');
        $dashboard_data['total_order'] = Order::myOrder()->count();
        $dashboard_data['today_register_user'] = User::where('user_type','client')->whereDate('created_at',today())->count();
               
        $total_compeleted_earning = Order::myOrder()->where('status', 'completed')->sum('total_amount');
        $total_cancelled_earning = Order::myOrder()->where('status', 'cancelled')->sum('total_amount');
        
        $dashboard_data['total_earning'] = $total_compeleted_earning + $total_cancelled_earning;
        $dashboard_data['total_cancelled_order'] = Order::myOrder()->where('status', 'cancelled')->count();

        $dashboard_data['total_create_order'] = Order::myOrder()->where('status', 'create')->count();
        $dashboard_data['total_active_order'] = Order::myOrder()->where('status', 'active')->count();
        $dashboard_data['total_delayed_order'] = Order::myOrder()->where('status', 'delayed')->count();
        $dashboard_data['total_courier_assigned_order'] = Order::myOrder()->where('status', 'courier_assigned')->count();
        $dashboard_data['total_courier_picked_up_order'] = Order::myOrder()->where('status', 'courier_picked_up')->count();
        $dashboard_data['total_courier_departed_order'] = Order::myOrder()->where('status', 'courier_departed')->count();
        $dashboard_data['total_courier_arrived_order'] = Order::myOrder()->where('status', 'courier_arrived')->count();
        $dashboard_data['total_completed_order'] = Order::myOrder()->where('status', 'completed')->count();
        $dashboard_data['total_failed_order'] = Order::myOrder()->where('status', 'failed')->count();

        $dashboard_data['today_create_order'] = Order::myOrder()->whereDate('created_at',today())->where('status', 'create')->count();
        $dashboard_data['today_active_order'] = Order::myOrder()->whereDate('created_at',today())->where('status', 'active')->count();
        $dashboard_data['today_delayed_order'] = Order::myOrder()->whereDate('created_at',today())->where('status', 'delayed')->count();
        $dashboard_data['today_cancelled_order'] = Order::myOrder()->whereDate('created_at',today())->where('status', 'cancelled')->count();
        $dashboard_data['today_courier_assigned_order'] = Order::myOrder()->whereDate('created_at',today())->where('status', 'courier_assigned')->count();
        $dashboard_data['today_courier_picked_up_order'] = Order::myOrder()->whereDate('created_at',today())->where('status', 'courier_picked_up')->count();
        $dashboard_data['today_courier_departed_order'] = Order::myOrder()->whereDate('created_at',today())->where('status', 'courier_departed')->count();
        $dashboard_data['today_courier_arrived_order'] = Order::myOrder()->whereDate('created_at',today())->where('status', 'courier_arrived')->count();
        $dashboard_data['today_completed_order'] = Order::myOrder()->whereDate('created_at',today())->where('status', 'completed')->count();
        $dashboard_data['today_failed_order'] = Order::myOrder()->whereDate('created_at',today())->where('status', 'failed')->count();

        $dashboard_data['app_setting'] = AppSetting::first();
        /*
        $upcoming_order = Order::myOrder()->whereDate('pickup_datetime','>=',Carbon::now()->format('Y-m-d H:i:s'))->orderBy('pickup_datetime','asc')->paginate(10);
        $dashboard_data['upcoming_order'] = OrderResource::collection($upcoming_order);
        */

        $upcoming_order = Order::myOrder()->whereNotIn('status',['draft', 'cancelled', 'completed'])->whereNotNull('pickup_point->start_time')
                        ->where('pickup_point->start_time','>=',Carbon::now()->format('Y-m-d H:i:s'))
                        ->orderBy('pickup_point->start_time','asc')->paginate(10);
        $dashboard_data['upcoming_order'] = OrderResource::collection($upcoming_order);

        $recent_order = Order::myOrder()->whereDate('date','<=',Carbon::now()->format('Y-m-d'))->orderBy('date','desc')->paginate(10);
        $dashboard_data['recent_order'] = OrderResource::collection($recent_order);

        $client = User::where('user_type','client')->orderBy('created_at','desc')->paginate(10);
        $dashboard_data['recent_client'] = UserResource::collection($client);

        $delivery_man = User::where('user_type','delivery_man')->orderBy('created_at','desc')->paginate(10);
        $dashboard_data['recent_delivery_man'] = UserResource::collection($delivery_man);

        $sunday = strtotime('sunday -1 week');
	    $sunday = date('w', $sunday) === date('w') ? $sunday + 7*86400 : $sunday;
        $saturday = strtotime(date('Y-m-d',$sunday).' +6 days');

        $week_start = date('Y-m-d 00:00:00',$sunday);
        $week_end = date('Y-m-d 23:59:59',$saturday);

        $dashboard_data['week'] = [
            'week_start'=> $week_start,
            'week_end'  => $week_end
        ];
        $weekly_order_count = Order::selectRaw('DATE_FORMAT(created_at , "%w") as days , DATE_FORMAT(created_at , "%Y-%m-%d") as date' )
                        ->whereBetween('created_at', [ $week_start, $week_end ])
                        ->get()->toArray();
        
                        $data = [];
        
        $order_collection = collect($weekly_order_count);
        for($i = 0; $i < 7 ; $i++){
            $total = $order_collection->filter(function ($value, $key) use($week_start, $i){
                return $value['date'] == date('Y-m-d',strtotime($week_start. ' + ' . $i . 'day'));
            })->count();
            
            $data[] = [
                'day' => date('l', strtotime($week_start . ' + ' . $i . 'day')),
                'total' => $total,
                'date' => date('Y-m-d',strtotime($week_start. ' + ' . $i . 'day')),    
            ];
        }

        $dashboard_data['weekly_order_count'] = $data;

        $user_week_report = User::selectRaw('DATE_FORMAT(created_at , "%w") as days , DATE_FORMAT(created_at , "%Y-%m-%d") as date' )
                        ->where('user_type','client')
                        ->whereBetween('created_at', [ $week_start, $week_end ])
                        ->get()->toArray();
        $data = [];
        
        $user_collection = collect($user_week_report);
        for($i = 0; $i < 7 ; $i++){
            $total = $user_collection->filter(function ($value, $key) use($week_start,$i){
                return $value['date'] == date('Y-m-d',strtotime($week_start. ' + ' . $i . 'day'));
            })->count();
            
            $data[] = [
                'day' => date('l', strtotime($week_start . ' + ' . $i . 'day')),
                'total' => $total,
                'date' => date('Y-m-d',strtotime($week_start. ' + ' . $i . 'day')),    
            ];
        }

        $dashboard_data['user_weekly_count'] = $data;
      
        $user = auth()->user();
        $dashboard_data['all_unread_count']  = isset($user->unreadNotifications) ? $user->unreadNotifications->count() : 0;
        
        $weekly_payment_report = Payment::myPayment()->selectRaw('DATE_FORMAT(created_at , "%w") as days , DATE_FORMAT(created_at , "%Y-%m-%d") as date, total_amount ' )
                        ->where('payment_status','paid')
                        ->whereBetween('created_at', [ $week_start, $week_end ])
                        ->get()->toArray();
        $data = [];

        $payment_collection = collect($weekly_payment_report);
        for($i = 0; $i < 7 ; $i++){
            $total_amount = $payment_collection->filter(function ($value, $key) use($week_start,$i){
                return $value['date'] == date('Y-m-d',strtotime($week_start. ' + ' . $i . 'day'));
            })->sum('total_amount');
            
            $data[] = [
                'day' => date('l', strtotime($week_start . ' + ' . $i . 'day')),
                'total_amount' => $total_amount,
                'date' => date('Y-m-d',strtotime($week_start. ' + ' . $i . 'day')),    
            ];
        }

        $dashboard_data['weekly_payment_report'] = $data;

        $month_start = Carbon::now()->startOfMonth();
        $today = Carbon::now();
        $diff = $month_start->diffInDays($today) + 1; // $today->daysInMonth;

        $dashboard_data['month'] = [
            'month_start'=> $month_start,
            'month_end'  => $today,
            'diff' => $diff,
        ];
        $monthly_order_count = Order::selectRaw('DATE_FORMAT(created_at , "%w") as days , DATE_FORMAT(created_at , "%Y-%m-%d") as date' )
                        ->whereBetween('created_at', [ $month_start, $today ])
                        ->get()->toArray();
        
        $monthly_order_count_data = [];
        
        $order_collection = collect($monthly_order_count);

        $monthly_payment_report = Payment::myPayment()->selectRaw('DATE_FORMAT(created_at , "%w") as days , DATE_FORMAT(created_at , "%Y-%m-%d") as date, total_amount ' )
            ->where('payment_status','paid')
            ->whereBetween('created_at', [ $month_start, $today ])
            ->whereHas('order',function ($query) {
                $query->where('status','completed');
            })->withTrashed()
            ->get()->toArray();
        
        $monthly_payment_completed_order_data = [];
               
        $payment_collection = collect($monthly_payment_report);
        
        $monthly_payment_cancelled_report = Payment::myPayment()->selectRaw('DATE_FORMAT(created_at , "%w") as days , DATE_FORMAT(created_at , "%Y-%m-%d") as date, cancel_charges ' )
            ->where('payment_status','paid')
            ->whereBetween('created_at', [ $month_start, $today ])
            ->whereHas('order',function ($query) {
                $query->where('status','cancelled');
            })->withTrashed()
            ->get()->toArray();
        
        $monthly_payment_cancelled_order_data = [];       
        $payment_cancelled_collection = collect($monthly_payment_cancelled_report);

        for($i = 0; $i < $diff ; $i++){
            $total = $order_collection->filter(function ($value, $key) use($month_start, $i){
                return $value['date'] == date('Y-m-d',strtotime($month_start. ' + ' . $i . 'day'));
            })->count();
            
            $monthly_order_count_data[] = [
                'total' => $total,
                'date' => date('Y-m-d',strtotime($month_start. ' + ' . $i . 'day')),    
            ];

            $total_amount = $payment_collection->filter(function ($value, $key) use($month_start,$i){
                return $value['date'] == date('Y-m-d',strtotime($month_start. ' + ' . $i . 'day'));
            })->sum('total_amount');
            
            $monthly_payment_completed_order_data[] = [
                'total_amount' => $total_amount,
                'date' => date('Y-m-d',strtotime($month_start. ' + ' . $i . 'day')),    
            ];

            $cancel_charges = $payment_cancelled_collection->filter(function ($value, $key) use($month_start,$i){
                return $value['date'] == date('Y-m-d',strtotime($month_start. ' + ' . $i . 'day'));
            })->sum('cancel_charges');
            
            $monthly_payment_cancelled_order_data[] = [ 
                'total_amount' => $cancel_charges,
                'date' => date('Y-m-d',strtotime($month_start. ' + ' . $i . 'day')),    
            ];
        }

        $dashboard_data['monthly_order_count'] = $monthly_order_count_data;
        $dashboard_data['monthly_payment_completed_report'] = $monthly_payment_completed_order_data;
        $dashboard_data['monthly_payment_cancelled_report'] = $monthly_payment_cancelled_order_data;

        $dashboard_data['country_city_data'] = Country::with('cities')->get();

        return $this->json_custom_response($dashboard_data);
    }


public function json_message_response( $message, $status_code = 200)
{	
	return response()->json( [ 'message' => $message ], $status_code );
}

public function  json_custom_response( $response, $status_code = 200 )
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


    public function dashboardChartData(Request $request)
    {
        $type = request('type');
        $month_start = Carbon::parse(request('start_at'))->startOfMonth();
        $month_end = Carbon::parse(request('end_at'))->endOfMonth();
        
        $diff = $month_start->diffInDays($month_end) + 1;
        $dashboard_data['month'] = [
            'month_start'=> $month_start,
            'month_end'  => $month_end,
            'diff' => $diff,
        ];
        $data = [];
        if( $type == 'monthly_order_count' )
        {
            $monthly_order_count = Order::selectRaw('DATE_FORMAT(created_at , "%w") as days , DATE_FORMAT(created_at , "%Y-%m-%d") as date' )
                ->whereBetween('created_at', [ $month_start, $month_end ])
                ->get()->toArray();
            
            $order_collection = collect($monthly_order_count);
            
            for($i = 0; $i < $diff ; $i++) {
                $total = $order_collection->filter(function ($value, $key) use($month_start, $i){
                    return $value['date'] == date('Y-m-d',strtotime($month_start. ' + ' . $i . 'day'));
                })->count();
                
                $data[] = [
                    'total' => $total,
                    'date' => date('Y-m-d',strtotime($month_start. ' + ' . $i . 'day')),    
                ];
            }
            $dashboard_data['monthly_order_count'] = $data;
        }

        if( $type == 'monthly_payment_completed_report' )
        {
            $monthly_payment_report = Payment::myPayment()->selectRaw('DATE_FORMAT(created_at , "%w") as days , DATE_FORMAT(created_at , "%Y-%m-%d") as date, total_amount ' )
                ->where('payment_status','paid')
                ->whereBetween('created_at', [ $month_start, $month_end ])
                ->whereHas('order',function ($query) {
                    $query->where('status','completed');
                })->withTrashed()
                ->get()->toArray();

            $payment_collection = collect($monthly_payment_report);

            for($i = 0; $i < $diff ; $i++) {
                $total_amount = $payment_collection->filter(function ($value, $key) use($month_start,$i){
                    return $value['date'] == date('Y-m-d',strtotime($month_start. ' + ' . $i . 'day'));
                })->sum('total_amount');
                $data[] = [
                    'total_amount' => $total_amount,
                    'date' => date('Y-m-d', strtotime($month_start. ' + ' . $i . 'day')),    
                ];
            }
            $dashboard_data['monthly_payment_completed_report'] = $data;
        }

        if( $type == 'monthly_payment_cancelled_report' )
        {
            $monthly_payment_report = Payment::myPayment()->selectRaw('DATE_FORMAT(created_at , "%w") as days , DATE_FORMAT(created_at , "%Y-%m-%d") as date, cancel_charges ' )
                ->where('payment_status','paid')
                ->whereBetween('created_at', [ $month_start, $month_end ])
                ->whereHas('order',function ($query) {
                    $query->where('status','cancelled');
                })->withTrashed()
                ->get()->toArray();
            
            $payment_collection = collect($monthly_payment_report);
            
            for($i = 0; $i < $diff ; $i++) {
                $cancel_charges = $payment_collection->filter(function ($value, $key) use($month_start,$i){
                    return $value['date'] == date('Y-m-d',strtotime($month_start. ' + ' . $i . 'day'));
                })->sum('cancel_charges');

                $data[] = [
                    'total_amount' => $cancel_charges,
                    'date' => date('Y-m-d', strtotime($month_start. ' + ' . $i . 'day')),    
                ];
            }
            $dashboard_data['monthly_payment_cancelled_report'] = $data;
        }

        return $this->json_custom_response($dashboard_data);
    }

    
    public function updateAvailabilitya(Request $request)
    {
        $user = auth()->user(); // login olan kullanıcı
    
        $request->validate([
            'is_active' => 'required|boolean',
        ]);
    
        $user->is_active = $request->is_active;
        $user->save();
    
        return response()->json([
            'message' => $user->is_active ? 'Durum: Aktif' : 'Durum: Müsait değil',
            'is_active' => $user->is_active,
        ]);
    }


public function updateAvailability(Request $request)
{
    $request->validate([
        'id' => 'required|exists:users,id',
        'is_active' => 'required|boolean',
    ]);

    $user = User::where('id', $request->id)->first();

    $user->is_active = $request->is_active;
    $user->save();

    return response()->json([
        'message' => $user->is_active ? 'Durum: Aktif' : 'Durum: Müsait değil',
        'is_active' => $user->is_active,
    ]);
}

    public function register2(UserRequest $request)
    {
        $input = $request->all();
                
        $password = $input['password'];
        $input['user_type'] = isset($input['user_type']) ? $input['user_type'] : 'client';
        $input['password'] = Hash::make($password);

        if( in_array($input['user_type'],['delivery_man']))
        {
            $input['status'] = isset($input['status']) ? $input['status']: 0;
        }
        $user = User::create($input);

        if( $request->has('user_bank_account') && $request->user_bank_account != null ) {
            $user->userBankAccount()->create($request->user_bank_account);
        }
        
        $message = __('message.save_form',['form' => __('message.'.$input['user_type']) ]);
        $user->api_token = $user->createToken('auth_token')->plainTextToken;
        
        $user_detail = User::where('id',$user->id)->first();
        $user->otp_verify_at = $user_detail->otp_verify_at ?? null;
        $user->email_verified_at = $user_detail->email_verified_at ?? null;
        $is_email_verification = SettingData('email_verification', 'email_verification') ?? "0";

        $response = [
            'message' => $message,
            'is_email_verification' => $is_email_verification,
            'data' => $user
        ];
        return $this->json_custom_response($response);
    }
 public function deliveryManListPickup(Request $request)
{
    $query = User::query();

    // Sadece kuryeleri al
    $query->where('user_type', 'delivery_man');

    // Sadece aktif ve açık olanları al
    $query->where('status', 1)
          ->where('is_active', true);

    // Silinmemiş olanlar
    $query->whereNull('deleted_at');

    // Eğer pickup latitude ve longitude gönderildiyse
    if ($request->has('pickup_latitude') && $request->has('pickup_longitude')) {
        $pickupLat = $request->pickup_latitude;
        $pickupLng = $request->pickup_longitude;

        // Longitude ve Latitude boş olmayan kullanıcıları al
        $query->whereNotNull('latitude')->whereNotNull('longitude');

        // Pickup noktasına göre mesafeyi hesapla ve mesafeye göre sırala
        $query->selectRaw("users.*, ST_Distance_Sphere(
            point(?, ?),
            point(users.longitude, users.latitude)
        ) as distance", [$pickupLng, $pickupLat])
        ->orderBy('distance', 'asc');
    } else {
        // Eğer pickup bilgisi yoksa id'ye göre sırala
        $query->orderBy('id', 'desc');
    }

    // Şehir filtresi varsa uygula
    if ($request->has('city_id')) {
        $query->where('city_id', $request->city_id);
    }

    // Sayfalama
    $per_page = config('constant.PER_PAGE_LIMIT');
    if ($request->has('per_page') && is_numeric($request->per_page)) {
        $per_page = $request->per_page;
    }

    // Listeyi çek
    $users = $query->paginate($per_page);

    // JSON yanıtı
    return $this->json_custom_response([
        'pagination' => json_pagination_response($users),
        'data' => UserResource::collection($users),
    ]);
}


   public function deliveryManList(Request $request)
    {
        $query = User::query();
    
        // Sadece kuryeler
        $query->where('user_type', 'delivery_man');
    
        // Sadece aktif kuryeler
      //  $query->where('status', 1);
    $query->where('status', 1)
      ->where('is_active', true);
      
        // Şehir filtresi
     
      //      $query->where('city_id','67');
    
    
        // Silinmemiş olanlar
        $query->whereNull('deleted_at');
    
        // İsteğe bağlı diğer filtreler (örneğin belge durumu gibi) ileride eklenebilir
    
        // Sayfalama
        $per_page = config('constant.PER_PAGE_LIMIT');
        if ($request->has('per_page') && is_numeric($request->per_page)) {
            $per_page = $request->per_page;
        }
    
        $users = $query->orderBy('id', 'desc')->paginate($per_page);
    
        return $this->json_custom_response([
            'pagination' => json_pagination_response($users),
            'data' => UserResource::collection($users),
        ]);
    }
    public function login()
    {      
        if(Auth::attempt(['email' => request('email'), 'password' => request('password')])){
            
        $user = auth()->user();
 $authUser = auth()->user();

$targetUser = User::where([
    ['email', '=', request('email')],
    ['city_id', '=', $authUser->city_id],
])->first();

if (!$targetUser) {
    return response()->json([
        'message' => 'Bu e-posta adresine sahip kullanıcı bulunamadı veya bu şehirde yetkiniz yok.'
    ], 403);
}


            if(request('player_id') != null){
                $user->player_id = request('player_id');
            }
            
            if(request('fcm_token') != null){
                $user->fcm_token = request('fcm_token');
            }

            $user->save();
            
            $success = $user;
            $success['api_token'] = $user->createToken('auth_token')->plainTextToken;
        //     $success['profile_image'] = this->jgetSingleMedia($user,'profile_image',null);
            $is_verified_delivery_man = false;
          /*   if($user->user_type == 'delivery_man') {
                $is_verified_delivery_man = DeliveryManDocument::verifyDeliveryManDocument($user->id);
            }
            */
          
       //     $success['is_verified_delivery_man'] = (int) $is_verified_delivery_man;
       //     unset($success['media']);

       //     $is_email_verification = SettingData('email_verification', 'email_verification') ?? "0";
        $is_email_verification = "0";
          return  $this->json_custom_response([ 'data' => $success, 'is_email_verification' => $is_email_verification ], 200 );
        }
        else{
            $message = __('auth.failed');
            
            return $this->json_message_response($message,400);
        }
    }
        public function toggleActive(Request $request)
        {
            $user = User::findOrFail($request->id);
        
            $user->is_active = !$user->is_active; // Toggle işlemi
            $user->save();
        
            return response()->json([
                'message' => $user->is_active ? 'Kullanıcı aktif yapıldı.' : 'Kullanıcı pasif yapıldı.',
                'is_active' => $user->is_active,
            ]);
        }







function getSingleMedia($model, $collection = 'profile_image', $skip=true   )
{
    if (!\Auth::check() && $skip) {
        return asset('images/user/user.png');
    }
    $media = null;
    if ($model !== null) {
        $media = $model->getFirstMedia($collection);
    }

    if (getFileExistsCheck($media))
    {
        return $media->getFullUrl();
    } else {
        switch ($collection) {
            case 'profile_image':
                $media = asset('images/user/user.png');
                break;
            case 'site_logo':
                $media = asset('images/logo.png');
                break;
            case 'site_favicon':
                $media = asset('images/favicon.png');
                break;
            default:
                $media = asset('images/default.png');
                break;
        }
        return $media;
    }
}

function getFileExistsCheck($media)
{
    $mediaCondition = false;

    if($media) {
        if($media->disk == 'public') {
            $mediaCondition = file_exists($media->getPath());
        } else {
            $mediaCondition = \Storage::disk($media->disk)->exists($media->getPath());
        }
    }
    return $mediaCondition;
}


    public function userList(Request $request)
    {
        $user_type = isset($request['user_type']) ? $request['user_type'] : 'client';
        
      
        
    
      $user_list = User::query();
       
        //    $user_list->where('city_id', auth()->user()->city_id);

         
       $user_list->where('city_id', '67');
 

        $user_list->when(request('user_type'), function ($q) use($user_type) {
            return $q->where('user_type', $user_type);
        });
 
        $user_list->when(request('country_id'), function ($q) {
            return $q->where('country_id', request('country_id'));
        });

        $user_list->when(request('city_id'), function ($q) {
            return $q->where('city_id', request('city_id'));
        });
 
        if( $request->has('status') && isset($request->status) )
        {
            $user_list = $user_list->where('status',request('status'));
        }
        
        if( $request->has('is_deleted') && isset($request->is_deleted) && $request->is_deleted){
            $user_list = $user_list->withTrashed();
        }


        $per_page = config('constant.PER_PAGE_LIMIT');
        if( $request->has('per_page') && !empty($request->per_page))
        {
            if(is_numeric($request->per_page)){
                $per_page = $request->per_page;
            }
            if($request->per_page == -1 ){
                $per_page = $user_list->count();
            }
        }
        
        $user_list = $user_list->orderBy('id','desc')->paginate($per_page);

        $items = UserResource::collection($user_list);

        $response = [
            'pagination' => json_pagination_response($items),
            'data' => $items,
        ];
        
        return $this->json_custom_response($response);
    }
    public function userFilterList(Request $request)
    {
        $params = $request->search;
        $user_list = User::where('name', 'LIKE', '%' . $params . '%');

        $user_type = $request->has('user_type') ? request('user_type') : 'client';
        
        $user_list->when(request('user_type'), function ($q) use($user_type) {
            return $q->where('user_type', $user_type);
        });

        if($request->has('is_deleted') && isset($request->is_deleted) && $request->is_deleted){
            $user_list = $user_list->withTrashed();
        }

        $per_page = config('constant.PER_PAGE_LIMIT');
        if( $request->has('per_page') && !empty($request->per_page))
        {
            if(is_numeric($request->per_page)){
                $per_page = $request->per_page;
            }
            if($request->per_page == -1 ){
                $per_page = $user_list->count();
            }
        }
        
        $user_list = $user_list->orderBy('id','desc')->paginate($per_page);

        $items = UserResource::collection($user_list);

        $response = [
            'pagination' => json_pagination_response($items),
            'data' => $items,
        ];
        
        return $this->json_custom_response($response);
    }

    public function userDetail(Request $request)
    {
        $id = $request->id;

        $user = User::where('id',$id)->withTrashed()->first();
        if(empty($user))
        {
            $message = __('message.user_not_found');
            return  $this->json_message_response($message,400);   
        }

        $user_detail = new UserResource($user);

        $response = [
            'data' => $user_detail
        ];
        return  $this->json_custom_response($response);

    }

    public function commonUserDetail(Request $request)
    {
        $id = $request->id;

        $user = User::where('id',$id)->withTrashed()->first();
        if(empty($user))
        {
            $message = __('message.user_not_found');
            return json_message_response($message,400);   
        }

        $user_detail = new UserDetailResource($user);

        $wallet_history = $user->userWalletHistory()->orderBy('id','desc')->paginate(10);
        $wallet_history_items = WalletHistoryResource::collection($wallet_history);
        $response = [
            'data' => $user_detail,
            'wallet_history' => [
                'pagination' => json_pagination_response($wallet_history_items),
                'data'  => $wallet_history_items,
            ]
        ];
        if( $user->user_type == 'delivery_man' ) {
            $earning_detail = User::select('id','name')->withTrashed()->where('id', $user->id)
                ->with(['userWallet:total_amount,total_withdrawn', 'getPayment:order_id,delivery_man_commission,admin_commission'])
                ->withCount(['deliveryManOrder as total_order',
                    'getPayment as paid_order' => function ($query) {
                        $query->where('payment_status', 'paid');
                    }
                ])
                ->withSum('userWallet as wallet_balance', 'total_amount')
                ->withSum('userWallet as total_withdrawn', 'total_withdrawn')
                ->withSum('getPayment as delivery_man_commission', 'delivery_man_commission')
                ->withSum('getPayment as admin_commission', 'admin_commission')->first();

            $response['earning_detail'] = new DeliveryManEarningResource($earning_detail);

            $earning_list = Payment::with('order')->withTrashed()->where('payment_status','paid')
                ->whereHas('order',function ($query) use($user) {
                    $query->whereIn('status',['completed','cancelled'])->where('delivery_man_id', $user->id);
                })->orderBy('id','desc')->paginate(10);
            
            $earning_list_items = PaymentResource::collection($earning_list);
            $response['earning_list']['pagination'] = json_pagination_response($earning_list_items);
            $response['earning_list']['data'] = $earning_list_items;
        }

        return $this->json_custom_response($response);
    }

    public function changePassword2(Request $request){
        $user = User::where('id',\Auth::user()->id)->first();

        if($user == "") {
            $message = __('message.user_not_found');
            return json_message_response($message,400);   
        }
           
        $hashedPassword = $user->password;

        $match = Hash::check($request->old_password, $hashedPassword);

        $same_exits = Hash::check($request->new_password, $hashedPassword);
        if ($match)
        {
            if($same_exits){
                $message = __('message.old_new_pass_same');
                return json_message_response($message,400);
            }

			$user->fill([
                'password' => Hash::make($request->new_password)
            ])->save();
            
            $message = __('message.password_change');
            return json_message_response($message,200);
        }
        else
        {
            $message = __('message.valid_password');
            return json_message_response($message,400);
        }
    }

    public function updateProfileClient(UserUpdateRequest $request)
    {   
        $user = \Auth::user();
        if($request->has('id') && !empty($request->id)){
            $user = User::where('id',$request->id)->first();
        }
        if($user == null){
            return json_message_response(__('message.not_found_entry',['name' => __('message.client')]),400);
        }

        $user->fill($request->all())->update();

        if(isset($request->profile_image) && $request->profile_image != null ) {
            $user->clearMediaCollection('profile_image');
            $user->addMediaFromRequest('profile_image')->toMediaCollection('profile_image');
        }

        $user_data = User::find($user->id);
        if($user_data->userBankAccount != null && $request->has('user_bank_account')) {
            $user_data->userBankAccount->fill($request->user_bank_account)->update();
        } else if( $request->has('user_bank_account') && $request->user_bank_account != null ) {
            $user_data->userBankAccount()->create($request->user_bank_account);
        }
                
        $message = __('message.updated');
        // $user_data['profile_image'] = getSingleMedia($user_data,'profile_image',null);
        unset($user_data['media']);
        $response = [
            'data' => new UserResource($user_data),
            'message' => $message
        ];
        return $this->json_custom_response( $response );
    }

    public function logout(Request $request)
    {
        $user = Auth::user();

        if($request->is('api*')){

            $clear = request('clear');
            if( $clear != null ) {
                $user->$clear = null;
            }
            $user->save();
            return json_message_response('Logout successfully');
        }
    }

    public function forgetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $response = Password::sendResetLink(
            $request->only('email')
        );

        return $response == Password::RESET_LINK_SENT
            ? response()->json(['message' => __($response), 'status' => true], 200)
            : response()->json(['message' => __($response), 'status' => false], 400);
    }
    
    public function socialLogin(Request $request)
    {
        $input = $request->all();

        $user_data = User::where('email',$input['email'])->first();

        if($input['login_type'] === 'mobile'){
            $user_data = User::where('username',$input['username'])->where('login_type','mobile')->first();
        }

        if( $user_data != null ) {
            if( !isset($user_data->login_type) || $user_data->login_type  == '' )
            {
                if(in_array($request->login_type, [ 'google', 'apple']))
                {
                    $message = __('validation.unique',['attribute' => 'email' ]);
                } else {
                    $message = __('validation.unique',['attribute' => 'username' ]);
                }
                return json_message_response($message,400);
            }

            if( $user_data->user_type != request('user_type') ) {
                if(in_array($request->login_type, [ 'google', 'apple'])) {
                    $message = __('validation.unique',['attribute' => 'email' ]);
                } else {
                    $message = __('validation.unique',['attribute' => 'username' ]);
                }
                return json_message_response($message,400);
            }
            $message = __('message.login_success');
        } else {

            if(in_array($request->login_type, [ 'google', 'apple']))
            {
                $key = 'email';
                $value = $request->email;
            } else {
                $key = 'username';
                $value = $request->username;
            }
            
            $trashed_user_data = User::where($key,$value)->whereNotNull('login_type')->withTrashed()->first();
            
            if ($trashed_user_data != null && $trashed_user_data->trashed())
            {
                if(in_array($request->login_type, [ 'google', 'apple'])){
                    $message = __('validation.unique',['attribute' => 'email' ]);
                } else {
                    $message = __('validation.unique',['attribute' => 'username' ]);
                }
                return json_message_response($message,400);
                
                if($request->login_type === 'mobile' && $user_data == null ){
                    $otp_response = [
                        'status' => true,
                        'is_user_exist' => false
                    ];
                    return $this->json_custom_response($otp_response);
                }
            }

            $password = !empty($input['accessToken']) ? $input['accessToken'] : $input['email'];
            if(in_array($request->login_type, [ 'google', 'apple'])) {
                $input['email_verified_at'] = date('Y-m-d H:i:s');
            }
            
            $input['password'] = Hash::make($password);
            $input['user_type'] = isset($input['user_type']) ? $input['user_type'] : 'client';
            $user = User::create($input);
    
            $user_data = User::where('id',$user->id)->first();
            $message = __('message.save_form',['form' => $input['user_type'] ]);
        }
        $user_data['api_token'] = $user_data->createToken('auth_token')->plainTextToken;
       // $user_data['profile_image'] = getSingleMedia($user_data,'profile_image',null);
        $response = [
            'status' => true,
            'message' => $message,
            'data' => $user_data
        ];
        return $this->json_custom_response($response);
    }

    public function updateUserStatus(Request $request)
    {
        $user_id = $request->id;
        $user = User::where('id',$user_id)->first();

        if($user == "") {
            $message = __('message.user_not_found');
            return json_message_response($message,400);
        }
        if($request->has('status')) {
            $user->status = $request->status;
        }
        if($request->has('uid')) {
            $user->uid = $request->uid;
        }
        
        if($request->has('latitude')) {
            $user->latitude = $request->latitude;
        }
        
        if($request->has('longitude')) {
            $user->longitude = $request->longitude;
        }

        if($request->has('fcm_token')) {
            $user->fcm_token = $request->fcm_token;
        }

        if($request->has('country_id')) {
            $user->country_id = $request->country_id;
        }

        if($request->has('city_id')) {
            $user->city_id = $request->city_id;
        }
        if($request->has('player_id')) {
            $user->player_id = $request->player_id;
        }
        
        if($request->has('otp_verify_at')) {
            $user->otp_verify_at = $request->otp_verify_at;
        }

        if($request->has('app_version')) {
            $user->app_version = $request->app_version;
        }
        
        if($request->has('app_source')) {
            $user->app_source = $request->app_source;
        }

        if($request->has('latitude') && $request->has('longitude') ) {
            $user->last_location_update_at = date('Y-m-d H:i:s');
        }

        $user->save();

        $message = __('message.update_form',['form' => __('message.status') ]);
        $response = [
            'data' => new UserResource($user),
            'message' => $message
        ];
        return $this->json_custom_response($response);
    }

    public function updateAppSetting(Request $request)
    {
        $data = $request->all();
        AppSetting::updateOrCreate(['id' => $request->id],$data);
        $message = __('message.save_form',['form' => __('message.app_setting') ]);
        $response = [
            'data' => AppSetting::first(),
            'message' => $message
        ];
        return $this->json_custom_response($response);
    }

    public function getAppSetting(Request $request)
    {
        if($request->has('id') && isset($request->id)){
            $data = AppSetting::where('id',$request->id)->first();
        } else {
            $data = AppSetting::first();
        }

        return $this->json_custom_response($data);
    }

    public function deleteUser(Request $request)
    {
        $user = User::find($request->id);

        $message = __('message.record_not_found');
        
        if( $user != '' ) {
            $user->delete();
            $message = __('message.msg_deleted',['name' => __('message.'.$user->user_type) ]);
        }
        
        if(request()->is('api/*')){
            return $this->json_custom_response(['message'=> $message , 'status' => true]);
        }
    }

    public function userAction(Request $request)
    {
        $id = $request->id;
        $user = User::withTrashed()->where('id',$id)->first();
        
        $message = __('message.record_not_found');
        if($request->type === 'restore'){
            $user->restore();
            $message = __('message.msg_restored',['name' => __('message.'.$user->user_type) ]);
        }

        if($request->type === 'forcedelete'){
            $user->forceDelete();
            $message = __('message.msg_forcedelete',['name' => __('message.'.$user->user_type) ]);
        }

        return $this->json_custom_response(['message'=> $message, 'status' => true]);
    }

    public function verifyOTPForEmail(Request $request)
    {
        $user = auth()->user();
        $code = request('code');

        $verification = VerificationCode::where('user_id',$user->id)->where('code', $code)->first();

        if( $verification != null) {
            $diff_minute = calculateDuration($verification->datetime);
            
            if( $diff_minute >= 10){
                $message = __('message.otp_expire');
                $status = 400;
            } else {
                $message = __('message.otp_verified');
                $status = 200;
                $user->update(['email_verified_at' => date('Y-m-d H:i:s')]);
            }
            $verification->delete();
        } else {
            $message = __('message.otp_invalid');
            $status = 400;
        }
        return json_message_response($message,$status);
    }

    public function resendOTPForEmail()
    {
        $user = auth()->user();
        if($user->email_verified_at != null) {
            return json_message_response(__('message.email_is_verified'));
        }
        $user->notify(new EmailVerification($user));

        return json_message_response(__('message.otp_send'));
    }
    public function multipleDeleteRecords(Request $request)
    {
        $multi_ids = $request->ids;
        $user_type = $request->user_type != null ? $request->user_type : 'client';
        $message = __('message.msg_fail_to_delete', ['item' => __('message.'.$user_type)]);

        foreach ($multi_ids as $id) {
            $user = User::withTrashed()->where('id',$id)->first();
            if ($user) {
                if( $user->deleted_at != null ) {
                    $user->forceDelete();
                } else {                        
                    $user->delete();
                }
                $message = __('message.msg_deleted',['name' => __('message.'.$user->user_type) ]);
            }
        }

        return $this->json_custom_response(['message'=> $message , 'status' => true]);
    }

    public function profile(Request $request)
{
    $user = $request->user(); // auth()->user()

    if (!$user) {
        return $this->json_message_response('Unauthenticated', 401);
    }

    // Eğer dealer_id ile başka bir bayi tablosu vs. kullanıyorsan burayı ona göre uyarlayabilirsin.
    // Şu an sadece user tablosundaki dealer_id ve vendor_id'yi dönüyorum.
    return $this->json_custom_response([
        'id'                => $user->id,
        'name'              => $user->name,
        'email'             => $user->email,
        'phone'             => $user->phone,
        'user_type'         => $user->user_type,
        'dealer_id'         => $user->dealer_id,
        'vendor_id'         => $user->vendor_id,
        'city_id'           => $user->city_id,
        'city'              => $user->city,
        'district'          => $user->district,
        'address'           => $user->address,
        'latitude'          => $user->latitude,
        'longitude'         => $user->longitude,
        'is_active'         => (bool) $user->is_active,
        'status'            => $user->status,
        'vehicle_plate'     => $user->vehicle_plate,
        'iban'              => $user->iban,
        'commission_rate'   => $user->commission_rate,
        'commission_type'   => $user->commission_type,
        'has_hadi_account'  => (bool) $user->has_hadi_account,
        'login_type'        => $user->login_type,
        'app_version'       => $user->app_version,
        'app_source'        => $user->app_source,
        'created_at'        => $user->created_at,
        'updated_at'        => $user->updated_at,
    ]);
}
public function updateProfile33(UserUpdateRequest $request)
{   
    $user = \Auth::user();

    // Eğer admin panelden başka user güncellenecekse, "id" ile override:
    if ($request->has('id') && !empty($request->id)) {
        $user = User::where('id', $request->id)->first();
    }

    if ($user == null) {
        return $this->json_message_response(
            __('message.not_found_entry', ['name' => __('message.client')]),
            400
        );
    }

    // Kullanıcı alanlarını doldur
    $user->fill($request->all())->update();

    // Profil resmi varsa medya koleksiyonuna at
    if ($request->hasFile('profile_image') && $request->file('profile_image') != null) {
        $user->clearMediaCollection('profile_image');
        $user->addMediaFromRequest('profile_image')->toMediaCollection('profile_image');
    }

    $user_data = User::find($user->id);

    // Banka hesabı
    if ($user_data->userBankAccount != null && $request->has('user_bank_account')) {
        $user_data->userBankAccount->fill($request->user_bank_account)->update();
    } elseif ($request->has('user_bank_account') && $request->user_bank_account != null) {
        $user_data->userBankAccount()->create($request->user_bank_account);
    }
                
    $message = __('message.updated');

    unset($user_data['media']);

    return $this->json_custom_response([
        'data'    => new UserResource($user_data),
        'message' => $message,
    ]);
}


public function updateProfile(UserUpdateRequest $request)
{
    $user = auth()->user();

    if ($request->has('id') && !empty($request->id)) {
        // Admin başka bir kullanıcıyı güncelliyorsa
        $user = User::find($request->id);
    }

    if (!$user) {
        return $this->json_message_response(__('message.user_not_found'), 404);
    }

    // Form request validasyonundan geçtik → fill()
    $user->fill($request->only([
        'name',
        'email',
        'phone',
        'city_id',
        'city',
        'district',
        'address',
        'latitude',
        'longitude',
        'dealer_id',
        'vendor_id',
        'is_active',
        'status',
        'vehicle_plate',
        'iban',
        'commission_rate',
        'commission_type',
    ]));

    $user->save();

    // Profil resmi
    if ($request->hasFile('profile_image')) {
        $user->clearMediaCollection('profile_image');
        $user->addMediaFromRequest('profile_image')->toMediaCollection('profile_image');
    }

    // Banka hesabı alt model
    if ($request->has('user_bank_account')) {
        if ($user->userBankAccount) {
            $user->userBankAccount->update($request->user_bank_account);
        } else {
            $user->userBankAccount()->create($request->user_bank_account);
        }
    }

    return $this->json_custom_response([
        'message' => __('message.updated'),
        'data' => new UserResource($user),
    ]);
}

public function changePassword(Request $request)
{
    $user = User::where('id', \Auth::user()->id)->first();

    if ($user == "") {
        $message = __('message.user_not_found');
        return $this->json_message_response($message, 400);
    }

    $request->validate([
        'old_password' => 'required|string',
        'new_password' => 'required|string|min:6',
    ]);

    $hashedPassword = $user->password;

    $match = Hash::check($request->old_password, $hashedPassword);
    $same_exits = Hash::check($request->new_password, $hashedPassword);

    if (!$match) {
        $message = __('message.valid_password');
        return $this->json_message_response($message, 400);
    }

    if ($same_exits) {
        $message = __('message.old_new_pass_same');
        return $this->json_message_response($message, 400);
    }

    $user->fill([
        'password' => Hash::make($request->new_password),
    ])->save();
    
    $message = __('message.password_change');
    return $this->json_message_response($message, 200);
}
public function uploadProfileDocument2(Request $request)
{
    $user = $request->user();

    if (!$user) {
        return $this->json_message_response(__('message.user_not_found'), 401);
    }

    $request->validate([
        'type' => 'required|string|max:50', // ikametgah, ehliyet vs.
        'file' => [
            'required',
            File::types(['pdf', 'jpg', 'jpeg', 'png'])
                ->max(10 * 1024), // 10 MB
        ],
    ]);

    // Kullanıcı modelinin Spatie Media Library ile
    // HasMedia, InteractsWithMedia kullandığından emin ol:
    //
    // class User extends Authenticatable implements HasMedia { use InteractsWithMedia; ... }

    $media = $user->addMediaFromRequest('file')
        ->usingName($request->type)
        ->usingFileName(time() . '_' . $request->file('file')->getClientOriginalName())
        ->toMediaCollection('documents'); // istediğin koleksiyon adı

    return $this->json_custom_response([
        'message' => __('message.save_form', ['form' => 'document']),
        'data'    => [
            'id'              => $media->id,
            'url'             => $media->getFullUrl(),
            'name'            => $media->name,
            'file_name'       => $media->file_name,
            'collection_name' => $media->collection_name,
            'type'            => $request->type,
            'created_at'      => $media->created_at,
        ],
    ]);
}


 
public function uploadProfileDocument(Request $request)
{
    // 1) Geçerli type değerleri
    $allowedTypes = [
        'residence',
        'driver_license',
        'good_conduct',
        'residence_pdf_path',
        'driver_license_front_path',
        'good_conduct_pdf_path',
    ];

    $request->validate([
        'type' => 'required|in:' . implode(',', $allowedTypes),
        'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5 MB
    ]);

    /** @var \App\Models\User $user */
    $user = auth()->user();
    if (!$user) {
        return $this->json_message_response('Yetkisiz istek', 401);
    }

    $file = $request->file('file');

    // (İsteğe bağlı) Ek uzantı kontrolü
    $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower($file->getClientOriginalExtension());
    if (!in_array($ext, $allowedExt, true)) {
        return $this->json_message_response('Geçersiz dosya uzantısı', 422);
    }

    // 2) type → kolon map’i
    $type = $request->input('type');

    $column = match ($type) {
        'residence', 'residence_pdf_path'          => 'residence_pdf_path',
        'driver_license', 'driver_license_front_path' => 'driver_license_front_path',
        'good_conduct', 'good_conduct_pdf_path'    => 'good_conduct_pdf_path',
        default => null,
    };

    if (!$column) {
        return $this->json_message_response('Geçersiz belge tipi', 422);
    }

    // 3) Eski dosyayı sil (varsa)
    if ($user->$column) {
        if (Storage::disk('public')->exists($user->$column)) {
            Storage::disk('public')->delete($user->$column);
        }
    }

    // 4) Yeni dosyayı kaydet
    $path = $file->store("documents/{$user->id}", 'public');
    $user->$column = $path;
    $user->save();

    return $this->json_custom_response([
        'message' => 'Belge yüklendi',
        'type'    => $type,
        'column'  => $column,
        'path'    => $path,
        'url'     => Storage::disk('public')->url($path),
    ]);
}
// ESKİ METODU COMPLETELY SİL / YORUM SATIRINA AL

public function register(Request $request)
{
    try {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'password'    => $data['password'],
            'user_type'   => 'client',
            'status'      => 1,
            'app_source'  => 'haldeki_web',
            'app_version' => '1.0.0',
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'status'  => true,
            'message' => 'Kayıt başarılı',
            'token'   => $token,
            'user'    => $user,
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => false,
            'errors' => $e->errors(),
        ], 422);
    }
}





public function changePasswordclient(Request $request)
{
    $user = Auth::user(); // ✅ ID gönderme yok, token’dan gelen user

    if (!$user) {
        return $this->json_message_response(__('message.user_not_found'), 401);
    }

    $request->validate([
        'old_password' => 'required|string',
        'new_password' => 'required|string|min:6', // istersen: confirmed
        // 'new_password' => 'required|string|min:6|confirmed',
    ]);

    // ✅ eski şifre doğru mu?
    if (!Hash::check($request->old_password, $user->password)) {
        return $this->json_message_response(__('message.valid_password'), 400);
    }

    // ✅ yeni şifre eskisiyle aynı mı?
    if (Hash::check($request->new_password, $user->password)) {
        return $this->json_message_response(__('message.old_new_pass_same'), 400);
    }

    $user->password = Hash::make($request->new_password);
    $user->save();

    // (opsiyonel) şifre değişince tokenları iptal etmek istersen:
    // $user->tokens()->delete();

    return $this->json_message_response(__('message.password_change'), 200);
}



}
