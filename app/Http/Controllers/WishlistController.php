<?php

namespace App\Http\Controllers;

use App\Models\Wishlist;
use Illuminate\Http\Request;
use App\Repositories\LocalProductRepository;

class WishlistController extends Controller
{
    public function index(Request $request, LocalProductRepository $products)
    {
        $user = $request->user('api');
        if (!$user) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $wishlists = Wishlist::where('user_id', $user->id)->get();
        
        $items = [];
        foreach ($wishlists as $wishlist) {
            try {
                $product = $products->findByPancakeId($wishlist->product_id);
                if (!$product) {
                    continue;
                }
                // Extract image
                $image = null;
                if (!empty($product['images']) && is_array($product['images'])) {
                    $firstImage = $product['images'][0];
                    $image = is_array($firstImage) ? ($firstImage['image_path'] ?? null) : $firstImage;
                }
                
                $items[] = [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'price' => $product['price'] ?? 0,
                    'image' => $image,
                    'is_active' => $product['is_active'] ?? true,
                    // FE wishlist uses 'main_image' object
                    'main_image' => ['image_path' => $image]
                ];
            } catch (\Exception $e) {
                // If product not found on pancake, skip or provide fallback
                continue;
            }
        }
        
        return $this->successResponse($items);
    }

    public function toggle(Request $request)
    {
        $request->validate([
            'product_id' => 'required'
        ]);

        $user = $request->user('api');
        if (!$user) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $productId = $request->product_id;

        $wishlist = Wishlist::where('user_id', $user->id)->where('product_id', $productId)->first();

        if ($wishlist) {
            $wishlist->delete();
            // Lấy id còn lại để FE tiện đồng bộ
            $remainingIds = Wishlist::where('user_id', $user->id)->pluck('product_id');
            return $this->successResponse(['status' => 'removed', 'wishlist_ids' => $remainingIds], 'Removed from wishlist');
        } else {
            Wishlist::create([
                'user_id' => $user->id,
                'product_id' => $productId
            ]);
            $remainingIds = Wishlist::where('user_id', $user->id)->pluck('product_id');
            return $this->successResponse(['status' => 'added', 'wishlist_ids' => $remainingIds], 'Added to wishlist');
        }
    }
}
