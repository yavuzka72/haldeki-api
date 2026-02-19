<?php

namespace App\Http\Controllers\Api;
 


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Category;
use Illuminate\Support\Facades\Schema;


class CategoryController extends Controller
{
    // GET /v1/categories  -> aktif kategoriler
    public function index()
    {
        $cats = Category::query()
            ->where('is_active', 1)
            ->orderBy('order')
            ->orderBy('name')
            ->get(['id','name','slug','image','description','order']);

        return response()->json(['data' => $cats]);
    }

    // POST /v1/categories/by-user  { email } veya { user_id }
       public function byUser(Request $request)
    {
        return $this->catlist($request); // aynı işlev
    }

    // İSTEDİĞİN metod adı: catlist
    public function catlist(Request $request)
    {
        $perPage = (int) ($request->input('per_page', 100));
        $email   = trim((string) $request->input('email', ''));
        $q       = $request->input('q');
        $term    = $q ? '%'.$q.'%' : null;

        // EMAIL YOKSA → public kategori listesi
        if ($email === '') {
            $builder = DB::table('categories')
                ->where('is_active', 1);

            if ($term) {
                $builder->where('name', 'like', $term);
            }

            $p = $builder
                ->orderBy('order')->orderBy('name')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'data'   => [
                    'data' => $p->items(),
                    'meta' => [
                        'current_page' => $p->currentPage(),
                        'per_page'     => $p->perPage(),
                        'total'        => $p->total(),
                        'last_page'    => $p->lastPage(),
                    ],
                ],
            ]);
        }

        // EMAIL VARSA → kullanıcıya tanımlı ürünlerden DISTINCT kategoriler
        $userId = DB::table('users')->where('email', $email)->value('id');
        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        $q = DB::table('user_products as up')
            ->join('products as p', 'p.id', '=', 'up.product_id')
            ->join('category_product as cp', 'cp.product_id', '=', 'p.id')
            ->join('categories as c', 'c.id', '=', 'cp.category_id')
            ->where('up.user_id', $userId)
            ->where('p.active', 1)
            ->where('c.is_active', 1);

        if (Schema::hasColumn('user_products', 'active')) {
            $q->where('up.active', 1);
        }
        if ($term) {
            $q->where('c.name', 'like', $term);
        }

        $q->select('c.id', 'c.name', 'c.slug', 'c.image', 'c.description', 'c.order')
          ->distinct()
          ->orderBy('c.order')
          ->orderBy('c.name');

        $p = $q->paginate($perPage);

        return response()->json([
            'status' => true,
            'data'   => [
                'data' => $p->items(),
                'meta' => [
                    'current_page' => $p->currentPage(),
                    'per_page'     => $p->perPage(),
                    'total'        => $p->total(),
                    'last_page'    => $p->lastPage(),
                ],
            ],
        ]);
    }
}
