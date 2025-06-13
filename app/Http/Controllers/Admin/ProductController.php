<?php


namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'sku' => 'required_without:variants|string|unique:product_variants,sku',
            
            // Variants validation - cho phép null hoặc array
            'variants' => 'nullable|array',
            'variants.*.sku' => 'required_with:variants|string|unique:product_variants,sku',
            'variants.*.price' => 'required_with:variants|numeric|min:0',
            'variants.*.original_price' => 'nullable|numeric|min:0',
            'variants.*.stock_quantity' => 'required_with:variants|integer|min:0',
            'variants.*.attributes' => 'required_with:variants|array',
            
            // Images validation
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:5120'
        ]);
    
        DB::transaction(function () use ($request) {
            // Create product
            $product = Product::create([
                'name' => $request->name,
                'description' => $request->description,
                'category_id' => $request->category_id,
                'is_new' => $request->boolean('is_new'),
                'is_featured' => $request->boolean('is_featured'),
                'slug' => Str::slug($request->name)

            ]);
    
            // Handle general images
            $this->handleGeneralImages($request, $product);
    
            // Handle variants
            $variants = $request->get('variants');

            // Inject lại image nếu có file
            foreach ($variants as $i => $variant) {
                if ($request->hasFile("variants.$i.image")) {
                    $variants[$i]['image'] = $request->file("variants.$i.image");   
                }
            }
            if (!empty($variants) && is_array($variants)) {
                
                $this->createVariants($variants, $product);
            } else {
                // Create single variant if no variants specified
                $this->createSingleVariant($request, $product);
            }
        });
    
        return response()->json(['message' => 'Product created successfully']);
    }
    
    private function handleGeneralImages($request, $product)
    {
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products', 'public');
                
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => $path,
                    'alt_text' => $product->name,
                    'sort_order' => $index,
                    'type' => 'general'
                ]);
            }
        }
    }
    
    private function createVariants($variants, $product)
    {
        foreach ($variants as $variantData) {
            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $variantData['sku'],
                'price' => $variantData['price'],
                'original_price' => $variantData['original_price'] ?? null,
                'stock_quantity' => $variantData['stock_quantity'],
                'attributes' => $variantData['attributes']
            ]);
            \Log::info(["Variant",$variant]);
            // Handle variant image if exists
            if (isset($variantData['image']) && $variantData['image'] instanceof \Illuminate\Http\UploadedFile) {
                $path = $variantData['image']->store('products/variants', 'public');
            
                try {
                    ProductImage::create([
                        'product_id' => $product->id,
                        'product_variant_id' => $variant->id,
                        'image_path' => $path,
                        'alt_text' => $product->name, // hoặc build từ attributes nếu chắc
                        'sort_order' => 0,
                        'type' => 'variant'
                    ]);
                } catch (\Throwable $e) {
                    \Log::error("Lỗi lưu variant image: " . $e->getMessage());
                }
            }
            
        }
    }
    
    private function createSingleVariant($request, $product)
    {
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $request->sku,
            'price' => $request->price,
            'original_price' => $request->original_price,
            'stock_quantity' => $request->stock_quantity,
            'attributes' => []
        ]);
    }

    public function destroy(Product $product)
    {
        $product->delete();
        return response()->json(['message' => 'Product deleted']);
    }


    public function index(Request $request)
    {
        try {
            // Khởi tạo query với các relationships
            $query = Product::with([
                'category:id,name',
                'images' => function($query) {
                    if (Schema::hasColumn('product_images', 'sort_order')) {
                        $query->orderBy('sort_order', 'asc');
                    }
                },
                'variants' => function($query) {
                    $variantColumns = Schema::getColumnListing('product_variants');
                    $selectFields = ['id', 'product_id', 'attributes'];
                    
                    // Thêm các cột nếu tồn tại trong bảng variants
                    foreach (['sku', 'price', 'original_price', 'stock_quantity'] as $col) {
                        if (in_array($col, $variantColumns)) {
                            $selectFields[] = $col;
                        }
                    }
                    $query->select($selectFields);
                 
                }
            ]);

            // Tìm kiếm theo tên sản phẩm
            if ($request->has('search') && !empty($request->search)) {
                $searchTerm = $request->search;
                $query->where(function($q) use ($searchTerm) {
                    $q->where('name', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('description', 'LIKE', "%{$searchTerm}%");
                    
                    // Tìm kiếm trong slug nếu có
                    if (Schema::hasColumn('products', 'slug')) {
                        $q->orWhere('slug', 'LIKE', "%{$searchTerm}%");
                    }
                });
            }

            // Lọc theo category
            if ($request->has('category_id') && !empty($request->category_id)) {
                $query->where('category_id', $request->category_id);
            }

            // Lọc theo trạng thái
            if ($request->has('status')) {
                switch ($request->status) {
                    case 'new':
                        $query->where('is_new', true);
                        break;
                    case 'featured':
                        $query->where('is_featured', true);
                        break;
                    case 'active':
                        $query->where('is_active', true);
                        break;
                    case 'inactive':
                        $query->where('is_active', false);
                        break;
                    case 'out_of_stock':
                        // Lọc sản phẩm hết hàng dựa trên variants
                        $query->whereHas('variants', function($q) {
                            $q->havingRaw('SUM(stock_quantity) <= 0');
                        });
                        break;
                    case 'low_stock':
                        // Lọc sản phẩm sắp hết hàng
                        $query->whereHas('variants', function($q) {
                            $q->havingRaw('SUM(stock_quantity) > 0 AND SUM(stock_quantity) < 10');
                        });
                        break;
                }
            }

            // Chỉ hiển thị sản phẩm active (trừ khi có filter khác)
            if (!$request->has('status') || $request->status !== 'inactive') {
                $query->where('is_active', true);
            }

            // Sắp xếp
            $sortField = $request->get('sort_field', 'created_at');
            $sortDirection = $request->get('sort_direction', 'desc');
            
            $allowedSortFields = ['name', 'created_at', 'updated_at'];
            
            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortDirection);
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Phân trang
            $perPage = $request->get('per_page', 15);
            $products = $query->paginate($perPage);

            // Transform data để thêm thông tin từ variants
            $products->getCollection()->transform(function ($product) {
                // Tính toán thông tin từ variants
                if ($product->variants && $product->variants->count() > 0) {
                    // Lấy giá thấp nhất và cao nhất
                    $prices = $product->variants->pluck('price')->filter();
                    $originalPrices = $product->variants->pluck('original_price')->filter();
                    
                    $product->min_price = $prices->min();
                    $product->max_price = $prices->max();
                    $product->price = $product->min_price; // Giá hiển thị chính
                    
                    if ($originalPrices->count() > 0) {
                        $product->min_original_price = $originalPrices->min();
                        $product->max_original_price = $originalPrices->max();
                        $product->original_price = $product->min_original_price;
                    }
                    
                    // Tính tổng stock từ tất cả variants
                    $product->total_stock = $product->variants->sum('stock_quantity');
                    $product->stock_quantity = $product->total_stock; // Để tương thích với UI
                    
                    // Lấy SKU đầu tiên (hoặc tạo danh sách SKUs)
                    $skus = $product->variants->pluck('sku')->filter();
                    $product->sku = $skus->first();
                    $product->all_skus = $skus->toArray();
                    
                } else {
                    // Nếu không có variants, set giá trị mặc định
                    $product->price = 0;
                    $product->original_price = null;
                    $product->total_stock = 0;
                    $product->stock_quantity = 0;
                    $product->sku = null;
                    $product->all_skus = [];
                }

                // Tính discount percentage
                $product->discount_percentage = null;
                if ($product->original_price && $product->original_price > $product->price) {
                    $product->discount_percentage = round((($product->original_price - $product->price) / $product->original_price) * 100);
                }

                // Đếm số lượng variants
                $product->variants_count = $product->variants ? $product->variants->count() : 0;

                // Xác định trạng thái stock
                $product->stock_status = 'in_stock';
                if ($product->total_stock <= 0) {
                    $product->stock_status = 'out_of_stock';
                } elseif ($product->total_stock < 10) {
                    $product->stock_status = 'low_stock';
                }

                // Format giá tiền
                if ($product->min_price == $product->max_price) {
                    $product->formatted_price = number_format($product->price, 0, ',', '.') . ' ₫';
                } else {
                    $product->formatted_price = number_format($product->min_price, 0, ',', '.') . ' - ' . number_format($product->max_price, 0, ',', '.') . ' ₫';
                }

                if ($product->original_price) {
                    $product->formatted_original_price = number_format($product->original_price, 0, ',', '.') . ' ₫';
                }

                // Thông tin ảnh
                $product->main_image = $product->images ? $product->images->first() : null;
                $product->images_count = $product->images ? $product->images->count() : 0;

                return $product;
            });

            return response()->json([
                'success' => true,
                'data' => $products->items(),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'from' => $products->firstItem(),
                    'to' => $products->lastItem(),
                ],
                'summary' => [
                    'total_products' => Product::where('is_active', true)->count(),
                    'out_of_stock' => Product::whereHas('variants', function($q) {
                        $q->havingRaw('SUM(stock_quantity) <= 0');
                    })->count(),
                    'low_stock' => Product::whereHas('variants', function($q) {
                        $q->havingRaw('SUM(stock_quantity) > 0 AND SUM(stock_quantity) < 10');
                    })->count(),
                    'featured_products' => Product::where('is_featured', true)->where('is_active', true)->count(),
                    'new_products' => Product::where('is_new', true)->where('is_active', true)->count(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi tải danh sách sản phẩm',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }

    // Thêm method để lấy thống kê nhanh
    public function statistics()
    {
        try {
            $stats = [
                'total_products' => Product::count(),
                'published_products' => Product::where('status', 'published')->count(),
                'draft_products' => Product::where('status', 'draft')->count(),
                'out_of_stock' => Product::where('stock_quantity', '<=', 0)->count(),
                'low_stock' => Product::where('stock_quantity', '>', 0)->where('stock_quantity', '<', 10)->count(),
                'featured_products' => Product::where('is_featured', true)->count(),
                'new_products' => Product::where('is_new', true)->count(),
                'total_variants' => \DB::table('product_variants')->count(),
                'categories_with_products' => Product::distinct('category_id')->count('category_id'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi tải thống kê',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Method để lấy sản phẩm theo ID với đầy đủ thông tin
    public function show($id)
    {
        try {
            $product = Product::with([
                'category',
                'images' => function($query) {
                    $query->orderBy('sort_order', 'asc');
                },
                'variants' => function($query) {
                    $query->with([
                        'attributes',
                        'images' => function($q) {
                            $q->orderBy('sort_order', 'asc');
                        }
                    ]);
                }
            ])->findOrFail($id);

            // Thêm thông tin bổ sung
            $product->discount_percentage = null;
            if ($product->original_price && $product->original_price > $product->price) {
                $product->discount_percentage = round((($product->original_price - $product->price) / $product->original_price) * 100);
            }

            $product->variants_count = $product->variants->count();
            $product->images_count = $product->images->count();

            return response()->json([
                'success' => true,
                'data' => $product
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sản phẩm',
                'error' => $e->getMessage()
            ], 404);
        }
    }


}