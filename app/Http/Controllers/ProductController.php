<?php

namespace App\Http\Controllers;

use App\Exports\ProductsExport;
use App\Imports\ProductsImport;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    private const IMG_DIR   = 'images/products';
    private const THUMB_DIR = 'images/products/thumbs';
    private const THUMB_W   = 120; // ធំជាង 40px បន្តិច សម្រាប់ retina

    public function index(Request $request)
    {
        // Categories ផ្លាស់ប្តូរតិចតួច → cache 10 នាទី, ទាញតែ column ចាំបាច់
        $categories = Cache::remember('categories_list', 600, function () {
            return Category::select('id', 'name')->orderBy('name')->get();
        });

        // List page មិនត្រូវការ creator/updater ទេ ហើយត្រូវការតែរូបទី ១
        $query = Product::select('id', 'name', 'sku', 'price', 'category_id')
            ->with([
                'category:id,name',
                'images' => fn ($q) => $q->select('id', 'product_id', 'image')->orderBy('id'),
            ])
            ->latest('id');

        if ($search = trim((string) $request->search)) {
            $query->where(function ($q) use ($search) {
                // name: contains (ត្រូវការ) | sku: prefix (ប្រើ index បាន)
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('sku', 'like', $search . '%');
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->paginate(10)->withQueryString();

        return view('pages/products.index', compact('products', 'categories'));
    }

    public function create()
    {
        $categories = Category::all();
        return view('pages/products.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string',
            'price'       => 'required|numeric',
            'stock'       => 'required|integer',
            'status'      => 'required|in:active,inactive',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:categories,id',
            'images.*'    => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5120',
        ], [
            'images.*.max'   => 'The image size must not be greater than 5MB.',
            'images.*.image' => 'The file must be an image.',
            'images.*.mimes' => 'Only jpeg, png, jpg, gif, and svg formats are allowed.',
        ]);

        $validated['add_to_pos'] = $request->has('add_to_pos') ? 1 : 0;
        $validated['created_by'] = Auth::id();
        $validated['updated_by'] = Auth::id();

        $product = Product::create($validated);

        $product->update([
            'sku' => 'kr' . $product->created_at->format('Ymd') . str_pad($product->id, 2, '0', STR_PAD_LEFT),
        ]);

        $this->saveImages($request, $product);

        return redirect()->route('products.create')->with('success', 'Product added successfully.');
    }

    public function show(string $id)
    {
        $product = Product::with(['images', 'creator', 'updater'])->findOrFail($id);
        return view('pages/products.show', compact('product'));
    }

    public function edit(string $id)
    {
        $product    = Product::with('images')->findOrFail($id);
        $categories = Category::all();
        return view('pages/products.edit', compact('product', 'categories'));
    }

    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'category_id'     => 'required|exists:categories,id',
            'price'           => 'required|numeric',
            'stock'           => 'required|integer',
            'status'          => 'required|in:active,inactive',
            'description'     => 'nullable|string|max:1000',
            'images.*'        => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5120',
            'delete_images'   => 'nullable|array',
            'delete_images.*' => 'integer|exists:product_images,id',
        ], [
            'images.*.max'   => 'The image size must not be greater than 5MB.',
            'images.*.image' => 'The file must be an image.',
            'images.*.mimes' => 'Only jpeg, png, jpg, gif, and svg formats are allowed.',
        ]);

        $validated['add_to_pos'] = $request->has('add_to_pos') ? 1 : 0;
        $validated['updated_by'] = Auth::id();

        $product = Product::findOrFail($id);
        $product->update($validated);

        if ($request->has('delete_images')) {
            $images = ProductImage::whereIn('id', $request->delete_images)
                ->where('product_id', $product->id)
                ->get();

            foreach ($images as $image) {
                $this->deleteImageFiles($image->image);
                $image->delete();
            }
        }

        $this->saveImages($request, $product);

        return redirect()->route('products.index')->with('success', 'Product updated successfully!');
    }

    public function destroy(string $id)
    {
        $product = Product::with('images')->findOrFail($id);

        foreach ($product->images as $image) {
            $this->deleteImageFiles($image->image);
            $image->delete();
        }

        $product->delete();

        return redirect()->route('products.index')->with('success', 'The product and its images deleted successfully.');
    }

    public function inventory()
    {
        $products = Product::all();

        $totalItems = $products->count();

        // កែ bug: កូដចាស់ប្រើ where() ជាមួយ closure ដែលមិនដំណើរការត្រឹមត្រូវ
        $lowStockItems = $products->filter(
            fn ($p) => $p->stock > 0 && $p->stock <= ($p->min_stock ?? 0)
        )->count();

        $outOfStockItems = $products->where('stock', '<=', 0)->count();

        $inventoryValue = $products->sum(fn ($p) => $p->stock * $p->price);

        return view('inventorys.index', compact(
            'products',
            'totalItems',
            'lowStockItems',
            'outOfStockItems',
            'inventoryValue'
        ));
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:5120',
        ]);

        $import = new ProductsImport();
        Excel::import($import, $request->file('file'));

        if ($import->failures()->isNotEmpty()) {
            $errors = $import->failures()->map(function ($failure) {
                return 'Row ' . $failure->row() . ': ' . implode(', ', $failure->errors());
            });

            return redirect()->route('products.index')
                ->with('error', 'Some rows failed to import.')
                ->with('import_errors', $errors);
        }

        return redirect()->route('products.index')->with('success', 'Products imported successfully.');
    }

    public function export(Request $request)
    {
        $filename = 'products_' . now()->format('Ymd_His') . '.xlsx';
        return Excel::download(new ProductsExport($request), $filename);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $products = Product::with('images')->whereIn('id', $request->ids)->get();

        foreach ($products as $product) {
            foreach ($product->images as $image) {
                $this->deleteImageFiles($image->image);
                $image->delete();
            }
            $product->delete();
        }

        $count = $products->count();

        return redirect()->route('products.index')
            ->with('success', $count . ' product' . ($count === 1 ? '' : 's') . ' deleted successfully.');
    }

    /* ------------------------------------------------------------------
     |  Image helpers
     * ------------------------------------------------------------------ */

    private function saveImages(Request $request, Product $product): void
    {
        if (!$request->hasFile('images')) {
            return;
        }

        foreach ($request->file('images') as $file) {
            // ឈ្មោះ unique ការពារការ overwrite ពេលឈ្មោះឯកសារដូចគ្នា
            $filename = time() . '_' . Str::random(6) . '.' . $file->getClientOriginalExtension();
            $file->move(public_path(self::IMG_DIR), $filename);

            $this->makeThumbnail($filename);

            ProductImage::create([
                'product_id' => $product->id,
                'image'      => $filename,
            ]);
        }
    }

    private function deleteImageFiles(string $filename): void
    {
        foreach ([self::IMG_DIR, self::THUMB_DIR] as $dir) {
            $path = public_path($dir . '/' . $filename);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * បង្កើត thumbnail ដោយប្រើ GD (មិនត្រូវការ package បន្ថែម)
     */
    public static function makeThumbnail(string $filename): bool
    {
        $src = public_path(self::IMG_DIR . '/' . $filename);
        if (!is_file($src) || !function_exists('imagecreatetruecolor')) {
            return false;
        }

        $info = @getimagesize($src);
        if (!$info) {
            return false; // ឧ. SVG → ប្រើរូបដើមវិញ
        }

        [$w, $h, $type] = $info;

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_GIF  => @imagecreatefromgif($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default        => false,
        };

        if (!$image) {
            return false;
        }

        // Crop ជាការ៉េ (ត្រូវនឹង object-fit: cover)
        $side = min($w, $h);
        $srcX = (int) (($w - $side) / 2);
        $srcY = (int) (($h - $side) / 2);
        $size = self::THUMB_W;

        $thumb = imagecreatetruecolor($size, $size);

        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF || $type === IMAGETYPE_WEBP) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
        }

        imagecopyresampled($thumb, $image, 0, 0, $srcX, $srcY, $size, $size, $side, $side);

        $dir = public_path(self::THUMB_DIR);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $dest = $dir . '/' . $filename;

        $ok = match ($type) {
            IMAGETYPE_PNG  => imagepng($thumb, $dest, 7),
            IMAGETYPE_GIF  => imagegif($thumb, $dest),
            IMAGETYPE_WEBP => imagewebp($thumb, $dest, 80),
            default        => imagejpeg($thumb, $dest, 80),
        };

        imagedestroy($image);
        imagedestroy($thumb);

        return (bool) $ok;
    }
}