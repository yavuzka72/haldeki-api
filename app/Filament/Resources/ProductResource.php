<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Columns\ImageColumn;
use Filament\Forms\Components\Select;

class ProductResource extends Resource {
	protected static ?string $model = Product::class;

	protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

	protected static ?string $navigationGroup = 'Ürün Yönetimi';

	protected static ?int $navigationSort = 1;

	protected static ?string $modelLabel = 'Ürün';

	protected static ?string $pluralModelLabel = 'Ürünler';

	public static function shouldRegisterNavigation(): bool {
		return Auth::user()?->admin_level === 1;
	}

	public static function form(Form $form): Form {
		return $form
			->schema([
				Forms\Components\Section::make('Ürün Bilgileri')
					->schema([
						Forms\Components\TextInput::make('name')
							->label('Ürün Adı')
							->required()
							->maxLength(255),
						FileUpload::make('image')
							->label('Ürün Fotoğrafı')
							->image()
							->imageEditor()
							->directory('products')
							->visibility('public')
							->maxSize(5120) // 5MB
							->helperText('Önerilen boyut: 600x400px. Maksimum dosya boyutu: 5MB'),
						Forms\Components\Textarea::make('description')
							->label('Açıklama')
							->maxLength(65535)
							->columnSpanFull(),
						Forms\Components\Toggle::make('active')
							->label('Aktif')
							->default(true),
						Select::make('categories')
							->multiple()
							->relationship('categories', 'name')
							->preload()
							->label('Kategoriler'),
					])
					->columns(2),
			]);
	}

	public static function table(Table $table): Table {
		return $table
			->columns([
				ImageColumn::make('image')
					->label('Fotoğraf')
					->circular()
					->defaultImageUrl(url('/images/product-placeholder.jpg')),
				Tables\Columns\TextColumn::make('name')
					->label('Ürün Adı')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('variants_count')
					->label('Varyant Sayısı')
					->counts('variants'),
				Tables\Columns\IconColumn::make('active')
					->label('Durum')
					->boolean(),
				Tables\Columns\TextColumn::make('created_at')
					->label('Oluşturulma')
					->dateTime('d.m.Y H:i')
					->sortable(),
			])
			->filters([
				Tables\Filters\TernaryFilter::make('active')
					->label('Durum')
					->placeholder('Tümü')
					->trueLabel('Aktif')
					->falseLabel('Pasif'),
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
			RelationManagers\VariantsRelationManager::class,
		];
	}

	public static function getPages(): array {
		return [
			'index' => Pages\ListProducts::route('/'),
			'create' => Pages\CreateProduct::route('/create'),
			'edit' => Pages\EditProduct::route('/{record}/edit'),
		];
	}
}
