<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class ItemsRelationManager extends RelationManager {
	protected static string $relationship = 'items';
	protected static ?string $title = 'Sipariş Kalemleri';
	protected static ?string $modelLabel = 'Ürün';
	protected static ?string $pluralModelLabel = 'Ürünler';

	public function form(Form $form): Form {
		return $form
			->schema([
				Forms\Components\Select::make('product_variant_id')
					->label('Ürün Varyantı')
					->options(function () {
						$query = \App\Models\ProductVariant::query()->where('active', true);

						if (Auth::user()->admin_level === 2) {
							$query->whereHas('product.users', function ($query) {
								$query->where('users.id', Auth::id());
							});
						}

						return $query->pluck('name', 'id');
					})
					->required()
					->searchable()
					->preload(),
				Forms\Components\Select::make('seller_id')
					->label('Satıcı')
					->relationship('seller', 'name')
					->required()
					->searchable()
					->preload(),
				Forms\Components\TextInput::make('quantity')
					->label('Miktar')
					->required()
					->numeric()
					->minValue(1),
				Forms\Components\TextInput::make('unit_price')
					->label('Birim Fiyat')
					->required()
					->numeric()
					->prefix('₺'),
				Forms\Components\TextInput::make('total_price')
					->label('Toplam Fiyat')
					->required()
					->numeric()
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
			]);
	}

	public function table(Table $table): Table {
		return $table
			->columns([
				Tables\Columns\TextColumn::make('productVariant.product.name')
					->label('Ürün')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('productVariant.name')
					->label('Varyant')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('seller.name')
					->label('Satıcı')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('quantity')
					->label('Miktar')
					->sortable(),
				Tables\Columns\TextColumn::make('unit_price')
					->label('Birim Fiyat')
					->money('TRY')
					->sortable(),
				Tables\Columns\TextColumn::make('total_price')
					->label('Toplam')
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
			])
			->headerActions([
				Tables\Actions\CreateAction::make(),
			])
			->actions([
				Tables\Actions\EditAction::make(),
				Tables\Actions\DeleteAction::make(),
			])
			->bulkActions([
				Tables\Actions\BulkActionGroup::make([
					Tables\Actions\DeleteBulkAction::make(),
				]),
			])
			 ->defaultSort('created_at', 'desc');
			 
	}
}
