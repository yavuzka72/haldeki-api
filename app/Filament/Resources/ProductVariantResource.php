<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductVariantResource\Pages;
use App\Filament\Resources\ProductVariantResource\RelationManagers;
use App\Models\ProductVariant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class ProductVariantResource extends Resource {
	protected static ?string $model = ProductVariant::class;

	protected static ?string $navigationIcon = 'heroicon-o-tag';

	protected static ?string $navigationGroup = 'Ürün Yönetimi';

	protected static ?string $navigationLabel = 'Ürün Fiyatları';

	protected static ?int $navigationSort = 2;

	protected static ?string $modelLabel = 'Ürün Fiyatı';

	protected static ?string $pluralModelLabel = 'Ürün Fiyatları';

	public static function form(Form $form): Form {
		return $form
			->schema([
				Forms\Components\Section::make('Varyant Bilgileri')
					->schema([
						Forms\Components\Select::make('product_id')
							->label('Ürün')
							->relationship('product', 'name')
							->required()
							->searchable()
							->preload(),
						Forms\Components\TextInput::make('name')
							->label('Varyant Adı')
							->required()
							->maxLength(255)
							->placeholder('Örn: 1 Kilogram, Yarım Kilo, 1 Kasa'),
						Forms\Components\Toggle::make('active')
							->label('Aktif')
							->default(true),
					])
					->columns(2),
			]);
	}

	public static function table(Table $table): Table {
		return $table
			->columns([
				Tables\Columns\TextColumn::make('product.name')
					->label('Ürün')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('name')
					->label('Varyant')
					->searchable(),
				Tables\Columns\TextColumn::make('average_price')
					->label('Ortalama Fiyat')
					->money('TRY')
					->sortable(),
				Tables\Columns\IconColumn::make('active')
					->label('Durum')
					->boolean(),
			])
			->filters([
				Tables\Filters\SelectFilter::make('product')
					->label('Ürün')
					->relationship('product', 'name')
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
		return [
			RelationManagers\PricesRelationManager::class,
		];
	}

	public static function getPages(): array {
		return [
			'index' => Pages\ListProductVariants::route('/'),
			'edit' => Pages\EditProductVariant::route('/{record}/edit'),
		];
	}

	public static function shouldRegisterNavigation(): bool {
		return Auth::user()?->admin_level === 1;
	}
}
