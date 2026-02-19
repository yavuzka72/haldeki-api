<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryManDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;


class DeliveryManDocumentController extends Controller
{
    /**
     * GET /delivery-man-document-list
     * Filtre + sayfalama ile liste.
     * Örn: ?delivery_man_id=123&per_page=50&q=ehliyet
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) ($request->integer('per_page') ?: 15);

        $query = DeliveryManDocument::query();

        // Basit filtre örnekleri — ihtiyaca göre artırabilirsiniz:
        if ($request->filled('delivery_man_id')) {
            $query->where('delivery_man_id', $request->input('delivery_man_id'));
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                  ->orWhere('type', 'like', "%{$q}%");
            });
        }

        // Soft-deleted kayıtları da göstermek isterseniz:
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $data = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /delivery-man-document/{id}
     */
    public function show(int $id): JsonResponse
    {
        $doc = DeliveryManDocument::withTrashed()->find($id);

        if (! $doc) {
            return response()->json([
                'success' => false,
                'message' => __('message.not_found_entry', ['name' => __('message.delivery_man_document')]),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $doc,
        ]);
    }

    /**
     * POST /delivery-man-document
     * id verilirse updateOrCreate ile günceller, yoksa oluşturur.
     * Dosya alanı: delivery_man_document (helper: uploadMediaFile)
     */
       public function list(Request $request, int $userId)
    {
        // Klasör: storage/app/public/delivery_man_documents/{userId}
        $disk = Storage::disk('public');
        $dir  = "delivery_man_documents/{$userId}";

        if (!$disk->exists($dir)) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'message' => 'Klasör bulunamadı veya boş.',
            ]);
        }

        // Alt klasörler dahil tüm dosyalar
        $all = $disk->allFiles($dir);

        $items = collect($all)->map(function ($path) use ($disk) {
            // Klasör kontrolü gerekmez, allFiles zaten dosya döndürür ama yine de güvenlik:
            if (! $disk->exists($path)) return null;

            // 0 bayt olanları ele
            $size = (int) $disk->size($path);
            if ($size <= 0) return null;

            // Zaman damgası
            $lastModified = $disk->lastModified($path);

            return [
                'name'         => basename($path),
                'path'         => $path,
                'url'          => $disk->url($path),  // public URL
                'size'         => $size,              // bayt
                'last_modified'=> date('c', $lastModified),
            ];
        })->filter()->values()->toArray();

        return response()->json([
            'success' => true,
            'data'    => $items,
        ]);
    }
     
     public function store(Request $request)
{
    // user_id -> delivery_man_id eşlemesi
    if ($request->filled('user_id') && !$request->filled('delivery_man_id')) {
        $request->merge(['delivery_man_id' => $request->user_id]);
    }

    $data = $request->except(['delivery_man_document', 'file', 'document', 'image']);
    $doc  = DeliveryManDocument::updateOrCreate(
        ['id' => $request->input('id')],
        $data
    );

    // Dosya alan adlarından ilk bulunanı al
    $file = $request->file('delivery_man_document')
         ?? $request->file('file')
         ?? $request->file('document')
         ?? $request->file('image');

    if ($file) {
        $dir  = 'delivery_man_documents/' . ($doc->delivery_man_id ?? 'unknown');
        $name = uniqid().'_'.$file->getClientOriginalName();
        // public diskine yaz
        $path = \Storage::disk('public')->putFileAs($dir, $file, $name);
        // istersen modeli path ile güncelle
        $doc->file_path = $path; // migration’da string kolon ekle
        $doc->save();
    }

    return response()->json([
        'success' => true,
        'data'    => $doc->fresh(),
    ]);
}

     public function store3(Request $request): JsonResponse
{
    $validated = $request->validate([
        'id'               => 'nullable|integer|exists:delivery_man_documents,id',
        'user_id'          => 'nullable|integer', // frontend'ten gelen
        'delivery_man_id'  => 'nullable|integer',
        'title'            => 'nullable|string|max:255',
        'type'             => 'nullable|string|max:100',
    ]);

    // 👇 user_id geldiyse delivery_man_id olarak ata
    if ($request->has('user_id') && !$request->has('delivery_man_id')) {
        $request->merge(['delivery_man_id' => $request->user_id]);
    }

    $data = $request->except(['delivery_man_document']);

    $result = DeliveryManDocument::updateOrCreate(
        ['id' => $request->input('id')],
        $data
    );

    if ($request->has('delivery_man_document')) {
        uploadMediaFile($result, $request->delivery_man_document, 'delivery_man_document');
    }

    $message = $result->wasRecentlyCreated
        ? __('message.save_form', ['form' => __('message.delivery_man_document')])
        : __('message.update_form', ['form' => __('message.delivery_man_document')]);

    return response()->json([
        'success' => true,
        'message' => $message,
        'data'    => $result->fresh(),
    ]);
}


    public function store2(Request $request): JsonResponse
    {
        // Basit validasyon — alan isimlerini projenize göre uyarlayın.
        // delivery_man_id zorunluysa aktif edin.
        $validated = $request->validate([
            'id'               => 'nullable|integer|exists:delivery_man_documents,id',
            'delivery_man_id'  => 'nullable|integer', // 'required|exists:users,id' vb. olabilir
            'title'            => 'nullable|string|max:255',
            'type'             => 'nullable|string|max:100',
            // 'delivery_man_document' // dosya/medya alanı — upload helper zaten kontrol ediyor
        ]);

        // Medya dışında kalan alanları al
        $data = $request->except(['delivery_man_document']);

        $result = DeliveryManDocument::updateOrCreate(
            ['id' => $request->input('id')],
            $data
        );

        // Dosya (veya base64/URL) upload helper
        if ($request->has('delivery_man_document')) {
            // uploadMediaFile(model, payload, collection)
            uploadMediaFile($result, $request->delivery_man_document, 'delivery_man_document');
        }

        $message = __('message.update_form', ['form' => __('message.delivery_man_document')]);
        if ($result->wasRecentlyCreated) {
            $message = __('message.save_form', ['form' => __('message.delivery_man_document')]);
        }

        // API standardı: başarı + kayıt geri döndür
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $result->fresh(),
        ]);
    }

    /**
     * PUT /delivery-man-document/{id}
     * (Güncelleme — store'u tekrar kullanabiliriz)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // store mantığını tekrar kullan
        $request->merge(['id' => $id]);
        return $this->store($request);
    }

    /**
     * DELETE /delivery-man-document/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $doc = DeliveryManDocument::find($id);

        $message = __('message.msg_fail_to_delete', ['item' => __('message.delivery_man_document')]);
        $success = false;

        if ($doc) {
            $doc->delete();
            $message = __('message.msg_deleted', ['name' => __('message.delivery_man_document')]);
            $success = true;
        }

        return response()->json([
            'success' => $success,
            'message' => $message,
        ]);
    }

    /**
     * POST /delivery-man-document/action
     * body: { id, type: restore|forcedelete }
     */
    public function action(Request $request): JsonResponse
    {
        $request->validate([
            'id'   => 'required|integer',
            'type' => 'required|string|in:restore,forcedelete',
        ]);

        $id  = (int) $request->input('id');
        $doc = DeliveryManDocument::withTrashed()->find($id);

        if (! $doc) {
            return response()->json([
                'success' => false,
                'message' => __('message.not_found_entry', ['name' => __('message.delivery_man_document')]),
            ], 404);
        }

        $message = __('message.not_found_entry', ['name' => __('message.delivery_man_document')]);

        if ($request->input('type') === 'restore') {
            $doc->restore();
            $message = __('message.msg_restored', ['name' => __('message.delivery_man_document')]);
        } elseif ($request->input('type') === 'forcedelete') {
            $doc->forceDelete();
            $message = __('message.msg_forcedelete', ['name' => __('message.delivery_man_document')]);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * POST /delivery-man-document/multiple-delete
     * body: { ids: [1,2,3] }
     */
    public function multipleDeleteRecords(Request $request): JsonResponse
    {
        $ids = $request->input('ids');

        if (! is_array($ids) || empty($ids)) {
            return response()->json([
                'success' => false,
                'message' => __('message.select_data'),
            ], 422);
        }

        $count = DeliveryManDocument::whereIn('id', $ids)->delete();

        return response()->json([
            'success' => true,
            'message' => __('message.msg_deleted', ['name' => __('message.delivery_man_document')]) . " ({$count})",
            'deleted' => $count,
        ]);
    }
}
