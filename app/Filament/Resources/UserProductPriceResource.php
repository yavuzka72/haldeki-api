<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserProductPriceResource\Pages;
use App\Models\UserProductPrice;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class UserProductPriceResource extends Resource {
	protected static ?string $model = UserProductPrice::class;
	protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
	protected static ?string $navigationGroup = 'Fiyat Yönetimi';
	protected static ?int $navigationSort = 3;
	protected static ?string $modelLabel = 'Ürün Fiyatı';
	protected static ?string $pluralModelLabel = 'Ürün Fiyatları';

	public static function shouldRegisterNavigation(): bool {
		// Hem süper admin hem de satıcılar görebilir
		return in_array(Auth::user()?->admin_level, [1, 2]);
	}

	public static function form(Form $form): Form {
		$form = parent::form($form);

		// Satıcılar sadece fiyat ve aktiflik durumunu değiştirebilir
		if (Auth::user()?->admin_level === 2) {
			return $form->schema([
				Forms\Components\TextInput::make('price')
					->label('Fiyat')
					->required()
					->numeric()
					->prefix('₺'),
				Forms\Components\Toggle::make('active')
					->label('Aktif'),
			]);
		}

		// Süper admin tüm alanları düzenleyebilir
		return $form;
	}

	public static function table(Table $table): Table {
		return $table
			->columns([
				Tables\Columns\TextColumn::make('user.name')
					->label('Kullanıcı')
					->searchable()
					->sortable()
					->visible(fn() => Auth::user()->admin_level === 1),
				Tables\Columns\TextColumn::make('productVariant.product.name')
					->label('Ürün')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('productVariant.name')
					->label('Varyant')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('price')
					->label('Fiyat')
					->money('TRY')
					->sortable(),
				Tables\Columns\IconColumn::make('active')
					->label('Durum')
					->boolean(),
				Tables\Columns\TextColumn::make('created_at')
					->label('Oluşturulma')
					->dateTime('d.m.Y H:i')
					->sortable(),
			])
			->filters([
				Tables\Filters\SelectFilter::make('user')
					->label('Kullanıcı')
					->relationship('user', 'name')
					->searchable()
					->preload()
					->visible(fn() => Auth::user()->admin_level === 1),
				Tables\Filters\SelectFilter::make('product')
					->label('Ürün')
					->relationship('productVariant.product', 'name')
					->searchable()
					->preload(),
				Tables\Filters\TernaryFilter::make('active')
					->label('Durum'),
			])
			->actions([
				Tables\Actions\EditAction::make(),
				Tables\Actions\DeleteAction::make(),
			])
			->bulkActions([
				Tables\Actions\BulkActionGroup::make([
					Tables\Actions\DeleteBulkAction::make(),
				]),
			]);
	}

	public static function getRelations(): array {
		return [];
	}

	public static function getPages(): array {
		return [
			'index' => Pages\ListUserProductPrices::route('/'),
			'create' => Pages\CreateUserProductPrice::route('/create'),
			'edit' => Pages\EditUserProductPrice::route('/{record}/edit'),
		];
	}

	public static function getEloquentQuery(): Builder {
		$query = parent::getEloquentQuery();

		// Satıcılar sadece kendi fiyatlarını görsün
		if (Auth::user()?->admin_level === 2) {
			$query->where('user_id', Auth::id());
		}

		return $query;
	}
}
