<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\PancakeService;

class CartController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    private function getCart(Request $request)
    {
        $cartId = $request->header('X-Cart-ID');
        $user = $request->user('api');

        if (!$cartId) {
            return null;
        }

        // 1. If Guest, just return the cart by cart_id
        if (!$user) {
            return Cart::firstOrCreate(['cart_id' => $cartId]);
        }

        // 2. If User, check for existing user cart
        $userCart = Cart::where('user_id', $user->id)->first();
        $sessionCart = Cart::where('cart_id', $cartId)->first();

        // If both exist and are different, MERGE sessionCart into userCart
        if ($userCart && $sessionCart && $userCart->id !== $sessionCart->id) {
            foreach ($sessionCart->items as $sessionItem) {
                // Check if item exists in user cart
                $existingItem = $userCart->items()
                    ->where('product_id', $sessionItem->product_id)
                    ->where('product_variant_id', $sessionItem->product_variant_id)
                    ->first();

                if ($existingItem) {
                    $existingItem->quantity += $sessionItem->quantity;
                    $existingItem->save();
                } else {
                    $sessionItem->cart_id = $userCart->id;
                    $sessionItem->save();
                }
            }
            $sessionCart->delete();
            return $userCart;
        }

        // If only session cart exists, claim it for user
        if ($sessionCart && !$userCart) {
            $sessionCart->update(['user_id' => $user->id]);
            return $sessionCart;
        }

        // If only user cart exists, return it
        if ($userCart) {
            return $userCart;
        }

        // If neither, create new for user (using current cart_id is fine, or generate new)
        return Cart::create([
            'cart_id' => $cartId,
            'user_id' => $user->id
        ]);
    }

    public function index(Request $request)
    {
        $cart = $this->getCart($request);

        if (!$cart) {
            return response()->json(['items' => [], 'total' => 0]);
        }

        // No need to load relations as we store data in 'data' column
        // $cart->load(['items']); 

        $items = $cart->items->map(function ($item) {
            return [
                'id' => $item->id, // Cart item ID
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'name' => $item->name, // Accessor from data
                'price' => $item->price, // Accessor from data
                'quantity' => $item->quantity,
                'image' => $item->image, // Accessor from data
                'slug' => Str::slug($item->name), // Generate slug from name on the fly if needed
            ];
        });

        $total = $items->sum(function ($item) {
            return $item['price'] * $item['quantity'];
        });

        return response()->json([
            'id' => $cart->cart_id,
            'items' => $items,
            'total' => $total
        ]);
    }

    public function add(Request $request)
    {
        $cart = $this->getCart($request);
        if (!$cart)
            return response()->json(['message' => 'Cart ID missing'], 400);

        $productId = $request->input('product_id');
        $quantity = $request->input('quantity', 1);
        $variantId = $request->input('variant_id'); // Optional, Pancake Variation ID

        // 1. Fetch product details from Pancake to validate and get info
        // We use $productId (which should be Pancake ID).
        // If variantId is present, we might need to find that specific variant details within the product response
        // OR if productId IS the variation ID (which getProducts often returns), we just fetch lookup.

        // Assumption: Frontend sends Product ID (Pancake ID). 
        // If it's a variation, Pancake API `getProducts` returns variations as items.
        // So `getPancakeProduct` usually returns the item details we need.

        try {
            // Note: PancakeService::getProduct($id) uses /shops/{id}/products/{product_id}
            // If the ID passed is a variation ID, let's hope Pancake supports looking it up or we need to adjust service.
            // Verified in Service: getProduct calls .../products/{id}.
            // If the frontend uses the ID from `getProducts`, it is likely a Variation ID (uuid).

            $pancakeProduct = $this->pancakeService->getProduct($productId);

            // Extract info
            $name = $pancakeProduct['name'];
            $price = $pancakeProduct['price'];
            $image = $pancakeProduct['images'][0]['image_path'] ?? null;
            if (empty($image) && !empty($pancakeProduct['images'][0])) {
                // handle if image is just string in some cases? 
                // mapProduct: 'images' => $pancakeProduct['images'] ?? []
                // usually array of objects or strings.
                // let's assume mapProduct returns standard array.
            }
            // If empty, try to fallback or use placeholder?
            if (is_array($pancakeProduct['images']) && count($pancakeProduct['images']) > 0) {
                // Check structure of images from mapProduct
                // it passes raw pancake images array usually.
                // let's check mapProduct again.
            }

        } catch (\Exception $e) {
            return response()->json(['message' => 'Product not found on Pancake'], 404);
        }

        $item = $cart->items()
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        if ($item) {
            $item->quantity += $quantity;
            // Update price/info if changed?
            $data = $item->data ?? [];
            $data['price'] = $price; // Update price to latest
            $item->data = $data;
            $item->save();
        } else {
            $cart->items()->create([
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
                'data' => [
                    'name' => $name,
                    'price' => $price,
                    'image' => $image,
                    // Add other details like color/size if available in $pancakeProduct or if we fetch variation specific.
                    // If productId is variation ID, $name is likely "Name (Size, Color)".
                ]
            ]);
        }

        return $this->index($request);
    }

    public function update(Request $request)
    {
        $cart = $this->getCart($request);
        if (!$cart)
            return response()->json(['message' => 'Cart empty'], 404);

        $productId = $request->input('product_id');
        $quantity = $request->input('quantity');

        $query = $cart->items()->where('product_id', $productId);
        if ($request->has('variant_id')) {
            $query->where('product_variant_id', $request->input('variant_id'));
        }

        $item = $query->first();

        if ($item) {
            if ($quantity <= 0) {
                $item->delete();
            } else {
                $item->update(['quantity' => $quantity]);
            }
        }

        return $this->index($request);
    }

    public function remove(Request $request)
    {
        $cart = $this->getCart($request);
        if (!$cart)
            return response()->json(['message' => 'Cart empty'], 404);

        $productId = $request->input('product_id');

        $query = $cart->items()->where('product_id', $productId);
        if ($request->has('variant_id')) {
            $query->where('product_variant_id', $request->input('variant_id'));
        }

        $query->delete();

        return $this->index($request);
    }
}
