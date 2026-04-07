<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\Pancake\PancakeProductService;

class CartController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeProductService $pancakeService)
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
            $data = $item->data ?? [];
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'name' => $item->name,
                'price' => $item->price,
                'quantity' => $item->quantity,
                'image' => $item->image,
                'attributes' => $data['attributes'] ?? [],
                'slug' => Str::slug($item->name),
            ];
        });

        $total = $items->sum(function ($item) {
            return $item['price'] * $item['quantity'];
        });

        return $this->successResponse([
            'id' => $cart->cart_id,
            'items' => $items,
            'total' => $total
        ]);
    }

    public function add(Request $request)
    {
        $cart = $this->getCart($request);
        if (!$cart)
            return $this->errorResponse('Cart ID missing', 400);

        $productId = $request->input('product_id');
        $quantity = $request->input('quantity', 1);
        $variantId = $request->input('variant_id');

        try {
            $pancakeProduct = $this->pancakeService->getProduct($productId);

            $name = $pancakeProduct['name'];
            $price = $pancakeProduct['price'];
            $image = null;
            $attributes = [];

            // Extract image from product
            if (!empty($pancakeProduct['images']) && is_array($pancakeProduct['images'])) {
                $firstImage = $pancakeProduct['images'][0];
                if (is_array($firstImage) && isset($firstImage['image_path'])) {
                    $image = $firstImage['image_path'];
                } elseif (is_string($firstImage)) {
                    $image = $firstImage;
                }
            }

            // If variant_id provided, find the specific variation for correct price/attributes
            if ($variantId && !empty($pancakeProduct['variations'])) {
                foreach ($pancakeProduct['variations'] as $variation) {
                    if ($variation['id'] === $variantId) {
                        $price = $variation['price'] ?? $price;
                        $attributes = $variation['attributes'] ?? [];

                        // Use variation image if available
                        if (!empty($variation['images'])) {
                            $varImg = $variation['images'][0];
                            if (is_array($varImg) && isset($varImg['image_path'])) {
                                $image = $varImg['image_path'];
                            } elseif (is_string($varImg)) {
                                $image = $varImg;
                            }
                        }

                        // Append variation attributes to name
                        if (!empty($attributes)) {
                            $attrParts = implode(', ', array_values($attributes));
                            $name = $pancakeProduct['name'] . " ($attrParts)";
                        }
                        break;
                    }
                }
            }

            // If no variant_id but product has variations, use first variation's ID
            if (!$variantId && !empty($pancakeProduct['variations'])) {
                $firstVariation = $pancakeProduct['variations'][0];
                $variantId = $firstVariation['id'];
                $price = $firstVariation['price'] ?? $price;
                $attributes = $firstVariation['attributes'] ?? [];
            }

        } catch (\Exception $e) {
            return $this->errorResponse('Product not found on Pancake', 404);
        }

        $item = $cart->items()
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        if ($item) {
            $item->quantity += $quantity;
            $data = $item->data ?? [];
            $data['price'] = $price;
            $data['attributes'] = $attributes;
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
                    'attributes' => $attributes,
                ]
            ]);
        }

        return $this->index($request);
    }

    public function update(Request $request)
    {
        $cart = $this->getCart($request);
        if (!$cart)
            return $this->errorResponse('Cart empty', 404);

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
            return $this->errorResponse('Cart empty', 404);

        $productId = $request->input('product_id');

        $query = $cart->items()->where('product_id', $productId);
        if ($request->has('variant_id')) {
            $query->where('product_variant_id', $request->input('variant_id'));
        }

        $query->delete();

        return $this->index($request);
    }
}
