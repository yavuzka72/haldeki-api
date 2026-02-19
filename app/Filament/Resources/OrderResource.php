<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Filament\Resources\OrderResource\RelationManagers\ItemsRelationManager;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Notification;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;

class OrderResource extends Resource {
	protected static ?string $model = Order::class;
	protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
	protected static ?string $navigationGroup = 'Sipariş Yönetimi';
	protected static ?int $navigationSort = 1;
	protected static ?string $modelLabel = 'Sipariş';
	protected static ?string $pluralModelLabel = 'Siparişler';

	public static function form(Form $form): Form {
		return $form
			->schema([
				Tabs::make('Sipariş Detayları')
					->tabs([
						Tab::make('Müşteri Bilgileri')
							->schema([
								Forms\Components\TextInput::make('user.name')
									->label('Müşteri Adı')
									->disabled()
									->visible(fn ($record) => !$record->is_guest_order),
								Forms\Components\TextInput::make('user.email')
									->label('E-posta')
									->disabled()
									->visible(fn ($record) => !$record->is_guest_order),
								Forms\Components\TextInput::make('restaurant_name')
									->label('Restoran Adı')
									->disabled()
									->visible(fn ($record) => $record->is_guest_order)
									->formatStateUsing(fn ($record) => $record->user->name ?? ''),
								Forms\Components\TextInput::make('restaurant_email')
									->label('Restoran E-posta')
									->disabled()
									->visible(fn ($record) => $record->is_guest_order)
									->formatStateUsing(fn ($record) => $record->user->email ?? ''),
								Forms\Components\Textarea::make('shipping_address')
									->label('Teslimat Adresi')
									->disabled()
									->columnSpanFull()
									->formatStateUsing(function ($record) {
										// Debug için
									/*	Log::info('Shipping Address:', [
											'order_address' => $record->shipping_address,
											'user_address' => $record->user->address ?? null,
											'is_guest' => $record->is_guest_order
										]);
										*/
										if ($record->is_guest_order) {
											return $record->shipping_address;
										}
										
										return $record->user->address ?? '';
									}),
								Forms\Components\TextInput::make('phone')
									->label('Telefon')
									->disabled()
									->formatStateUsing(function ($record) {
										if ($record->is_guest_order) {
											return $record->phone;
										}
										return $record->user->phone ?? '';
									}),
							])
							->columns(2),

						Tab::make('Sipariş Bilgileri')
							->schema([
								Forms\Components\TextInput::make('order_number')
									->label('Sipariş Numarası')
									->disabled(),
								Forms\Components\TextInput::make('total_amount')
									->label('Toplam Tutar')
									->disabled()
									->prefix('₺'),
								Forms\Components\Select::make('status')
									->label('Durum')
									->options([
								'pending' => 'Beklemede',
						'confirmed' => 'Hazırlanıyor',
						 'away' => 'Yolda',
                         'delivered' => 'Teslim Edildi',
						'cancelled' => 'İptal Edildi'
									])
									->required(),
								Forms\Components\Select::make('payment_status')
									->label('Ödeme Durumu')
									->options([
										'pending' => 'Beklemede',
										'paid' => 'Ödendi',
										'failed' => 'Başarısız'
									])
									->required(),
								Forms\Components\Textarea::make('note')
									->label('Sipariş Notu')
									->columnSpanFull(),
								Forms\Components\Select::make('seller_id')
									->label('Satıcı')
									->options(function ($record) {
										if (!$record || !$record->items->first()) return [];
										
										$productVariantId = $record->items->first()->product_variant_id;
										$productVariant = \App\Models\ProductVariant::find($productVariantId);
										
										return \App\Models\User::where('admin_level', 2)
											->whereHas('userProducts', function ($query) use ($productVariant) {
												$query->where('product_id', $productVariant->product_id)
													->where('user_products.active', 1);
											})
											->pluck('name', 'id');
									})
									->searchable()
									->preload()
									->live()
									->afterStateUpdated(function ($record, $state) {
										if ($record && $state) {
											$record->items()->update([
												'seller_id' => $state
											]);
											Notification::make()
												->success()
												->title('Satıcı başarıyla atandı')
												->send();
										}
									})
									->visible(fn ($record) => $record?->is_guest_order)
									->columnSpanFull(),
							])
							->columns(2),

						Tab::make('Ürünler')
							->schema([
								Forms\Components\Placeholder::make('items')
									->content(function ($record) {
										if (!$record) return 'Sipariş yükleniyor...';
										
										return view('filament.components.order-items-list', [
											'items' => $record->items()->with(['productVariant.product'])->get()
										]);
									})
							])
					])
					->columnSpanFull()
			]);
	}

	public static function table(Table $table): Table {
		return $table
			->columns([
				Tables\Columns\TextColumn::make('order_number')
					->label('Sipariş No')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('user.name')
					->label('Müşteri')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('total_amount')
					->label('Toplam Tutar')
					->money('TRY')
					->sortable(),
				Tables\Columns\SelectColumn::make('status')
					->label('Durum')
					->options([
								'pending' => 'Beklemede',
						'confirmed' => 'Hazırlanıyor',
						 'away' => 'Yolda',
                         'delivered' => 'Teslim Edildi',
						'cancelled' => 'İptal Edildi'
					])
					->sortable(),
				Tables\Columns\SelectColumn::make('payment_status')
					->label('Ödeme')
					->options([
						'pending' => 'Beklemede',
						'paid' => 'Ödendi',
						'failed' => 'Başarısız'
					])
					->sortable(),
				Tables\Columns\TextColumn::make('created_at')
					->label('Tarih')
					->dateTime('d.m.Y H:i')
					->sortable(),
			])
			->filters([
				Tables\Filters\SelectFilter::make('status')
					->label('Durum')
					->options([
							'pending' => 'Beklemede',
						'confirmed' => 'Hazırlanıyor',
						 'away' => 'Yolda',
                         'delivered' => 'Teslim Edildi',
						'cancelled' => 'İptal Edildi'
					]),
				Tables\Filters\SelectFilter::make('payment_status')
					->label('Ödeme Durumu')
					->options([
						'pending' => 'Beklemede',
						'paid' => 'Ödendi',
						'failed' => 'Başarısız'
					]),
			])
			->actions([
				Tables\Actions\ViewAction::make(),
				Tables\Actions\EditAction::make(),
			])
			->bulkActions([
				Tables\Actions\BulkActionGroup::make([
					Tables\Actions\DeleteBulkAction::make(),
				]),
			])
			
		 ->defaultSort('created_at', 'desc');
	}

	public static function getRelations(): array {
		return [
		];
	}

	public static function getPages(): array {
		return [
			'index' => Pages\ListOrders::route('/'),
			'edit' => Pages\EditOrder::route('/{record}/edit'),
		];
	}

	public static function getEloquentQuery(): Builder {
		return parent::getEloquentQuery()
			->with(['user', 'items.productVariant.product']);
	}

	public static function shouldRegisterNavigation(): bool {
		return Auth::user()?->admin_level === 1;
	}

	public static function mutateFormDataBeforeSave(array $data, $record = null): array {
		// Sipariş kalemlerinden toplam tutarı hesapla
		if ($record) {
			$record->total_amount = $record->items->sum('total_price');
			$record->save();
		}

		return $data;
	}
}
