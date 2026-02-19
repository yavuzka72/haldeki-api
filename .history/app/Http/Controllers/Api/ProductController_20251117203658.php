<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Api\VariantPriceController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Product;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
 
use App\Models\UserProductPrice as UPP;
use Illuminate\Validation\Rule;

use App\Models\ProductVariant;
use App\Models\UserProductPrice;



class ProductController extends Controller {
	use ApiResponse;


  public function storefull(Request $request)
    {
        $data = $request->validate([
            'name'           => ['required','string','max:255'],
            'description'    => ['nullable','string'],
            'image'          => ['nullable','string'], // upload path
            'active'         => ['nullable','boolean'],

            // kategoriler
            'category_ids'   => ['array'],
            'category_ids.*' => ['integer','exists:categories,id'],

            // varyantlar
            'variants'       => ['array'],
            'variants.*.name'=> ['required','string','max:255'],
            'variants.*.unit'=> ['nullable','string','max:50'],
            'variants.*.active' => ['nullable','boolean'],
            'variants.*.price'  => ['nullable','numeric','min:0'], // ilk fiyat (opsiyonel)

            // fiyatı yazacak kullanıcı (opsiyonel, yoksa auth()->user())
            'email'          => ['nullable','email', Rule::exists('users','email')],
        ]);

        // ÜRÜN
        $product = Product::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'image'       => $data['image'] ?? null,
            'active'      => $data['active'] ?? true,
        ]);

        // KATEGORİ EŞLEME
        if (!empty($data['category_ids'])) {
            $product->categories()->sync($data['category_ids']);
        }

        // FİYATI YAZACAK USER
        $priceOwner = $request->user(); // auth()->user()
        if (!$priceOwner && !empty($data['email'])) {
            $priceOwner = User::where('email', $data['email'])->first();
        }

        // VARYANTLAR (+ opsiyonel fiyatlar)
        $createdVariants = [];
        foreach (($data['variants'] ?? []) as $v) {
            $variant = $product->variants()->create([
                'name'   => $v['name'],
                'unit'   => $v['unit'] ?? null,
                'active' => $v['active'] ?? true,
            ]);
            $createdVariants[] = $variant;

            // İlk fiyat gönderilmişse user_product_prices tablosuna yaz
            if (($v['price'] ?? null) !== null && $priceOwner) {
                UserProductPrice::updateOrCreate(
                    [
                        'user_id'            => $priceOwner->id,
                        'product_variant_id' => $variant->id,
                    ],
                    [
                        'price'  => $v['price'],
                        'active' => true,
                    ]
                );
            }
        }

        // yanıtı zenginleştir
        $product->load(['categories:id,name', 'variants.prices' => function($q){
            $q->where('active', true);
        }])->append('image_url');

        return response()->json([
            'success' => true,
            'product' => $product,
        ], 201);
    }
public function store(Request $request)
{
    $data = $request->validate([
        'name'        => 'required|string|max:255',
        'description' => 'nullable|string',
        'image'       => 'nullable|string',   // Flutter upload sonrası path/url stringi
        'active'      => 'boolean',
        // gerekiyorsa: 'category_id' => ['nullable','integer','exists:categories,id'],
    ]);

    $product = \App\Models\Product::create([
        'name'        => $data['name'],
        'description' => $data['description'] ?? null,
        'image'       => $data['image'] ?? null,
        'active'      => $data['active'] ?? true,
        // 'category_id' => $data['category_id'] ?? null,
    ]);

    return response()->json([
        'success' => true,
        'product' => $product
    ], 201);
}
public function productsOfUser(Request $request)
{
    $data = $request->validate([
        'email'       => 'required|email',
        'only_active' => 'sometimes|boolean', // pivot.active filtresi
    ]);

    $user = User::where('email', $data['email'])->firstOrFail();

    // Product’tan isim çekiyoruz; pivot’ta user filtresi
    $products = Product::query()
        ->select('products.id', 'products.name') // isim products’tan
        ->whereHas('users', function ($q) use ($user, $data) {
            $q->where('users.id', $user->id);
            if (($data['only_active'] ?? false) === true) {
                $q->wherePivot('active', 1);
            }
        })
        ->with(['users' => function ($q) use ($user) {
            // İstersen pivot bilgilerini görmek için (active vs.)
            $q->where('users.id', $user->id)->select('users.id')->withPivot('active');
        }])
        ->orderBy('products.name')
        ->get();

    return response()->json([
        'success' => true,
        'data'    => $products,
    ]);
}

public function update(Request $request, Product $product)
{
    $data = $request->validate([
        'name' => 'string|max:255',
        'active' => 'boolean',
    ]);

    $product->update($data);
    return response()->json(['success' => true, 'product' => $product]);
}



	public function index2(Request $request) {
		$products = Product::query()
			->where('active', true)
			->with(['variants' => function ($query) {
				$query->where('active', true);
			}])
			->when($request->search, function ($query, $search) {
				$query->where('name', 'like', "%{$search}%");
			})
			->paginate(20);

		return $this->success($products);
	}
	
	
public function index(Request $request)
{
    // --- Esnek parametre eşleme ---
    $q = $request->input('q', $request->input('search'));

    $catId = $request->input('category_id',
        $request->input('category',
            $request->input('categoryId',
                // hem "filters[category_id]" hem "filters.category_id"
                $request->input('filters[category_id]', $request->input('filters.category_id'))
            )
        )
    );

    $perPage = (int) $request->input('per_page', 20);
    $includeVariants = $request->boolean('include_variants', true);

    // --- Sorgu ---
    $query = Product::query()
        ->where('active', true)
        ->when($q, function ($qq) use ($q) {
            $qq->where('name', 'like', "%{$q}%");
        })
        // kategori pivot: category_product(product_id, category_id)
        ->when($catId, function ($qq) use ($catId) {
            $qq->whereHas('categories', function ($c) use ($catId) {
                $c->where('categories.id', (int) $catId);
            });
        });

    if ($includeVariants) {
        $query->with(['variants' => function ($v) {
            $v->where('active', true);
        }]);
    }

    $products = $query->paginate($perPage);

    // --- Görseli tam URL'ye çevir ---
    $products->getCollection()->transform(function ($p) {
        $p->image_url = null;
        if (!empty($p->image)) {
            $url = \Storage::disk('public')->url(ltrim($p->image, '/'));
            $p->image_url = preg_match('~^https?://~i', $url)
                ? $url
                : url('storage/' . ltrim($p->image, '/'));
        }
        return $p;
    });

    return $this->success($products);
}


	public function show(Product $product) {
		if (!$product->active) {
			return $this->error('Ürün bulunamadı.', 404);
		}

		$product->load(['variants' => function ($query) {
			$query->where('active', true);
		}]);

		return $this->success($product);
	}
	public function variantsUser(Request $request, Product $product)
{
    if (! $product->active) {
        return $this->error('Ürün bulunamadı.', 404);
    }

    // 1) user_id tespiti: auth varsa onu kullan; yoksa ?email= üzerinden
    $userId = optional($request->user())->id
        ?? User::where('email', $request->query('email'))->value('id');

    // 2) Varyantları çek, user_price alt sorgu ile ekle
    $variants = $product->variants()
        ->where('active', true)
        ->with(['prices' => function ($q) use ($userId) {
            // Tüm fiyatları göstermek istiyorsan bu bloğu boş bırakabilirsin.
            // Sadece o kullanıcıya ait fiyatlar gelsin dersen:
            if ($userId) {
                $q->where('user_id', $userId);
            }
            // "en son girilen" için yeni olan üstte
            $q->orderByDesc('id');
        }])
        ->addSelect([
            // o kullanıcıya ait EN SON fiyat (active olma şartı istemiyorsan where('active', true) kaldır)
            'user_price' => UPP::select('price')
                ->whereColumn('product_variant_id', 'product_variants.id')
                ->when($userId, fn ($qq) => $qq->where('user_id', $userId))
                // son girilen:
                ->latest('id')
                ->limit(1),
        ])
        ->get();

    // 3) Güvence: alt sorgu null dönerse, koleksiyondan son kaydı ata
    if ($userId) {
        $variants->each(function ($v) use ($userId) {
            if (is_null($v->user_price)) {
                $v->user_price = optional(
                    $v->prices->where('user_id', $userId)->sortByDesc('id')->first()
                )->price;
            }
        });
    }

    return $this->success($variants);
}
	public function variants(Product $product) {
		if (!$product->active) {
			return $this->error('Ürün bulunamadı.', 404);
		}

		$variants = $product->variants()
			->where('active', true)
			->with(['prices' => function ($query) {
				$query->where('active', true);
			}])
			->get();

		return $this->success($variants);
	}
        
        public function addVariant(Request $request, $productId)
        {
            $request->validate([
                'name' => 'required|string|max:255',
                'active' => 'boolean',
            ]);
        
            $product = Product::findOrFail($productId);
        
            $variant = $product->variants()->create([
                'name' => $request->name,
                'active' => $request->active ?? true,
            ]);
        
            return response()->json([
                'success' => true,
                'variant' => $variant
            ]);
        }
    public function productById(Request $request)
    {
        $data = $request->validate([
            'email'      => 'required|email',
            'product_id' => 'required|integer|exists:products,id',
        ]);

        // 1) user_id
        $userId = DB::table('users')->where('email', $data['email'])->value('id');
        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        $productId = (int) $data['product_id'];

        // 2) Kullanıcıya bu ürün tanımlı mı? (user_products)
        $assigned = DB::table('user_products')
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->where('active', 1)
            ->exists();

        if (!$assigned) {
            return response()->json(['status' => false, 'message' => 'Product is not assigned to this user'], 404);
        }

        // 3) Ürün bilgisi
        $product = DB::table('products')
            ->where('id', $productId)
            ->where('active', 1)
            ->select('id','name','image','active')
            ->first();

        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Product not found or inactive'], 404);
        }

        // 4) Subquery: (user_id, variant) için active=1 en son fiyat kaydı
        $latestPriceSub = DB::table('user_product_prices as upp')
            ->select('upp.user_id', 'upp.product_variant_id', 'upp.price')
            ->joinSub(
                DB::table('user_product_prices')
                    ->selectRaw('user_id, product_variant_id, MAX(id) as max_id')
                    ->where('active', 1)
                    ->groupBy('user_id', 'product_variant_id'),
                'last',
                function ($j) {
                    $j->on('last.user_id', '=', 'upp.user_id')
                      ->on('last.product_variant_id', '=', 'upp.product_variant_id')
                      ->on('last.max_id', '=', 'upp.id');
                }
            );

        // 5) Varyantlar + en güncel fiyat
        $q = DB::table('product_variants as pv')
            ->leftJoinSub($latestPriceSub, 'price_latest', function ($j) use ($userId) {
                $j->on('price_latest.product_variant_id', '=', 'pv.id')
                  ->where('price_latest.user_id', '=', $userId);
            })
            ->where('pv.product_id', $productId)
            ->where('pv.active', 1)
            ->selectRaw('pv.id as variant_id, pv.name as variant_name, price_latest.price as price')
            ->orderBy('pv.name');

        $variants = $q->get()->map(function ($row) {
            return [
                'variant_id'   => (int) $row->variant_id,
                'variant_name' => (string) $row->variant_name, // örn: "1 Kasa"
                'price'        => $row->price !== null ? (float) $row->price : null,
            ];
        });

        // 6) Görsel URL
        $imageUrl = null;
        if (!empty($product->image)) {
            $url = Storage::disk('public')->url(ltrim($product->image, '/'));
            if (preg_match('~^https?://~i', $url)) {
                $imageUrl = $url;
            } else {
                $imageUrl = url('storage/' . ltrim($product->image, '/'));
            }
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'product' => [
                    'id'          => (int) $product->id,
                    'name'        => (string) $product->name,
                    'image_url'   => $imageUrl,
                    'active'      => (bool) $product->active,
                ],
                'data' => $variants,   // varyant + fiyat listesi
                'meta' => [
                    'count' => $variants->count(),
                ],
            ],
        ]);
    }
    
    
       public function productsByUser3(Request $request)
    {
        $data = $request->validate([
            'user_id'         => 'required_without:email|integer|exists:users,id',
            'email'           => 'required_without:user_id|email',
            'per_page'        => 'sometimes|integer|min:1|max:500',
            'q'               => 'sometimes|string',
            'include_variants'=> 'sometimes|boolean',
        ]);

        // user_id tespiti
        $userId = $data['user_id'] ?? DB::table('users')->where('email', $data['email'] ?? '')->value('id');
        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        $perPage = (int) ($data['per_page'] ?? 50);
        $term    = isset($data['q']) && $data['q'] !== '' ? '%'.$data['q'].'%' : null;

        // user_products → products
        $q = DB::table('user_products as up')
            ->join('products as p', 'p.id', '=', 'up.product_id')
             
            ->when(Schema::hasColumn('user_products', 'active'), function ($qq) {
    return $qq->where('up.active', 1);
})
            ->where('p.active', 1)
            ->where('up.user_id', $userId)
             
            ->when($term, function ($qq, $value) {
    return $qq->where('p.name', 'like', $value);
})

            ->select('p.id', 'p.name', 'p.image', 'p.active')
            ->orderBy('p.name')
            ->distinct();

        $p = $q->paginate($perPage);

        // Ürün listesi + image_url
        $items = collect($p->items())->map(function ($r) {
            $imageUrl = null;
            if (!empty($r->image)) {
                $url = Storage::disk('public')->url(ltrim($r->image,'/'));
                $imageUrl = preg_match('~^https?://~i', $url) ? $url : url('storage/'.ltrim($r->image,'/'));
            }
            return [
                'id'        => (int) $r->id,
                'name'      => (string) $r->name,
                'active'    => (bool)  $r->active,
                'image_url' => $imageUrl,
            ];
        })->values();

        // include_variants=true ise: her ürünün aktif varyantlarını ve bu kullanıcıya göre son aktif fiyatını ekle
        if (!empty($data['include_variants'])) {
            $productIds = $items->pluck('id')->all();

            // (user, variant) için active=1 en son id'li fiyat
            $latestPriceSub = DB::table('user_product_prices as upp')
                ->select('upp.user_id', 'upp.product_variant_id', 'upp.price')
                ->joinSub(
                    DB::table('user_product_prices')
                        ->selectRaw('user_id, product_variant_id, MAX(id) as max_id')
                        ->where('active', 1)
                        ->groupBy('user_id', 'product_variant_id'),
                    'last',
                    function ($j) {
                        $j->on('last.user_id', '=', 'upp.user_id')
                          ->on('last.product_variant_id', '=', 'upp.product_variant_id')
                          ->on('last.max_id', '=', 'upp.id');
                    }
                );

            $rows = DB::table('product_variants as pv')
                ->leftJoinSub($latestPriceSub, 'price_latest', function ($j) use ($userId) {
                    $j->on('price_latest.product_variant_id', '=', 'pv.id')
                      ->where('price_latest.user_id', '=', $userId);
                })
                ->whereIn('pv.product_id', $productIds)
                ->where('pv.active', 1)
                ->selectRaw('pv.product_id, pv.id as variant_id, pv.name as variant_name, price_latest.price as price')
                ->orderBy('pv.name')
                ->get()
                ->groupBy('product_id');

            // ürünlere iliştir
            $items = $items->map(function ($prod) use ($rows) {
                $variants = ($rows[$prod['id']] ?? collect())->map(function ($v) {
                    return [
                        'variant_id'   => (int) $v->variant_id,
                        'variant_name' => (string) $v->variant_name,
                        'price'        => $v->price !== null ? (float) $v->price : null,
                    ];
                })->values();

                return $prod + ['variants' => $variants];
            })->values();
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'data' => $items,
                'meta' => [
                    'current_page' => $p->currentPage(),
                    'per_page'     => $p->perPage(),
                    'total'        => $p->total(),
                    'last_page'    => $p->lastPage(),
                ],
            ],
        ]);
    }  
     // app/Http/Controllers/Api/ProductController.php

public function productsByUser(Request $request)
{
    $data = $request->validate([
        'user_id'         => 'required_without:email|integer|exists:users,id',
        'email'           => 'required_without:user_id|email',
        'per_page'        => 'sometimes|integer|min:1|max:500',
        'q'               => 'sometimes|string',
        'include_variants'=> 'sometimes|boolean',
        'category_id'     => 'sometimes|integer|exists:categories,id', // <-- YENİ
    ]);

    // user_id tespiti
    $userId = $data['user_id'] ?? DB::table('users')->where('email', $data['email'] ?? '')->value('id');
    if (!$userId) {
        return response()->json(['status' => false, 'message' => 'User not found'], 404);
    }

    $perPage   = (int) ($data['per_page'] ?? 50);
    $term      = isset($data['q']) && $data['q'] !== '' ? '%'.$data['q'].'%' : null;
    $catId     = $data['category_id'] ?? null; // <-- YENİ

    // user_products → products (+ optional category pivot)
    $q = DB::table('user_products as up')
        ->join('products as p', 'p.id', '=', 'up.product_id')
        ->leftJoin('category_product as cp', 'cp.product_id', '=', 'p.id') // <-- YENİ
        ->where('p.active', 1)
        ->where('up.user_id', $userId)
        ->when($term, function ($qq) use ($term) {
            $qq->where('p.name', 'like', $term);
        })
        ->select('p.id', 'p.name', 'p.image', 'p.active')
        ->selectRaw('MIN(cp.category_id) as category_id') // <-- YENİ: ürünün birincil kategori id’si
        ->groupBy('p.id','p.name','p.image','p.active')
        ->orderBy('p.name');

    // up.active kolonu varsa aktif filtrele
    if (Schema::hasColumn('user_products', 'active')) {
        $q->where('up.active', 1);
    }

    // KATEGORİ FİLTRESİ (isteğe bağlı)
    if (!empty($catId)) {
        $q->where('cp.category_id', (int)$catId);
    }

    $p = $q->paginate($perPage);

    // Ürün listesi + image_url (opsiyonel storage logic’iniz varsa ekleyin)
    $items = collect($p->items())->map(function ($r) {
        return [
            'id'          => (int) $r->id,
            'name'        => (string) $r->name,
            'active'      => (bool)  $r->active,
            'image_url'   => $r->image,          // burada public url yoksa storage’a çevirin
            'category_id' => $r->category_id ? (int)$r->category_id : null, // <-- ÖNEMLİ
        ];
    })->values();

    // include_variants=true ise: aktif varyantlar + bu kullanıcıya göre son aktif fiyat
    if (!empty($data['include_variants'])) {
        $productIds = $items->pluck('id')->all();

        // (user, variant) için active=1 en son id’li fiyat
        $latestPriceSub = DB::table('user_product_prices as upp')
            ->select('upp.user_id', 'upp.product_variant_id', 'upp.price')
            ->joinSub(
                DB::table('user_product_prices')
                    ->selectRaw('user_id, product_variant_id, MAX(id) as max_id')
                    ->where('active', 1)
                    ->groupBy('user_id', 'product_variant_id'),
                'last',
                function ($j) {
                    $j->on('last.user_id', '=', 'upp.user_id')
                      ->on('last.product_variant_id', '=', 'upp.product_variant_id')
                      ->on('last.max_id', '=', 'upp.id');
                }
            );

        $rows = DB::table('product_variants as pv')
            ->leftJoinSub($latestPriceSub, 'price_latest', function ($j) use ($userId) {
                $j->on('price_latest.product_variant_id', '=', 'pv.id')
                  ->where('price_latest.user_id', '=', $userId);
            })
            ->whereIn('pv.product_id', $productIds)
            ->where('pv.active', 1)
            ->selectRaw('pv.product_id, pv.id as variant_id, pv.name as variant_name, price_latest.price as price')
            ->orderBy('pv.name')
            ->get()
            ->groupBy('product_id');

        $items = $items->map(function ($prod) use ($rows) {
            $variants = ($rows[$prod['id']] ?? collect())->map(function ($v) {
                return [
                    'variant_id'   => (int) $v->variant_id,
                    'variant_name' => (string) $v->variant_name,
                    'price'        => $v->price !== null ? (float) $v->price : null,
                    'unit'         => 'adet', // unit kolonu yoksa frontend default
                ];
            })->values();

            return $prod + ['variants' => $variants];
        })->values();
    }

    return response()->json([
        'status' => true,
        'data'   => [
            'data' => $items,
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page'     => $p->perPage(),
                'total'        => $p->total(),
                'last_page'    => $p->lastPage(),
            ],
        ],
    ]);
}

	public function prices(Product $product) {
		if (!$product->active) {
			return $this->error('Ürün bulunamadı.', 404);
		}

		$prices = $product->variants()
			->where('active', true)
			->with(['prices' => function ($query) {
				$query->where('active', true)
					->with('user:id,name');
			}])
			->get()
			->map(function ($variant) {
				return [
					'variant_id' => $variant->id,
					'variant_name' => $variant->name,
					'prices' => $variant->prices->map(function ($price) {
						return [
							'price' => $price->price,
							'seller' => $price->user->name
						];
					})
				];
			});

		return $this->success($prices);
	}
}
