<div class="space-y-4">
    @foreach($items as $item)
        <div class="p-4 bg-gray rounded-lg">
            <div class="flex justify-between items-start">
                <div>
                    <h3 class="font-medium">{{ $item->productVariant->product->name }}</h3>
                    <p class="text-sm text-gray-500">{{ $item->productVariant->name }}</p>
                </div>
                <div class="text-right">
                    <p class="font-medium">₺{{ number_format($item->total_price, 2) }}</p>
                    <p class="text-sm text-gray-500">{{ $item->quantity }} adet x ₺{{ number_format($item->unit_price, 2) }}</p>
                </div>
            </div>
        </div>
    @endforeach
</div> 