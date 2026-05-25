<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Repositories\LocalProductRepository;

class CartController extends Controller
{
    public function __construct(private LocalProductRepository $products)
    {
    }

    private function getCart(Request $request)
    {
        $cartId = $request->header('X-Cart-ID');
        $user = $request->bearerToken() ? $request->user('api') : null;

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
                    $existingItem->quantity = min(99, $existingItem->quantity + $sessionItem->quantity);
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
        $request->validate([
            'product_id' => 'required',
            'variant_id' => 'nullable',
            'quantity' => 'nullable|integer|min:1|max:99',
        ]);

        $cart = $this->getCart($request);
        if (!$cart)
            return $this->errorResponse('Cart ID missing', 400);

        $productId = $request->input('product_id');
        $quantity = $request->input('quantity', 1);
        $variantId = $request->input('variant_id');

        try {
            $resolvedItem = $this->products->resolveOrderItem($productId, $variantId, $quantity);
            $variantId = $resolvedItem['variation_id'];
            $quantity = $resolvedItem['quantity'];
            $pancakeProduct = $this->products->findByPancakeId($productId);
            $name = $resolvedItem['name'];
            $price = $resolvedItem['price'];
            $image = null;
            $attributes = $resolvedItem['attributes'];

            // Extract image from product
            if ($pancakeProduct && !empty($pancakeProduct['images']) && is_array($pancakeProduct['images'])) {
                $firstImage = $pancakeProduct['images'][0];
                if (is_array($firstImage) && isset($firstImage['image_path'])) {
                    $image = $firstImage['image_path'];
                } elseif (is_string($firstImage)) {
                    $image = $firstImage;
                }
            }

            // Use variation image when available.
            if ($variantId && $pancakeProduct && !empty($pancakeProduct['variations'])) {
                foreach ($pancakeProduct['variations'] as $variation) {
                    if ((string) $variation['id'] === (string) $variantId) {
                        if (!empty($variation['images'])) {
                            $varImg = $variation['images'][0];
                            if (is_array($varImg) && isset($varImg['image_path'])) {
                                $image = $varImg['image_path'];
                            } elseif (is_string($varImg)) {
                                $image = $varImg;
                            }
                        }
                        break;
                    }
                }
            }

        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        $item = $cart->items()
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        if ($item) {
            $nextQuantity = min(99, $item->quantity + $quantity);
            try {
                $this->products->resolveOrderItem($productId, $variantId, $nextQuantity);
            } catch (\Exception $e) {
                return $this->errorResponse($e->getMessage(), 400);
            }

            $item->quantity = $nextQuantity;
            $data = $item->data ?? [];
            $data['name'] = $name;
            $data['price'] = $price;
            $data['image'] = $image;
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
        $request->validate([
            'product_id' => 'required',
            'variant_id' => 'nullable',
            'quantity' => 'required|integer|min:0|max:99',
        ]);

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
                try {
                    $resolvedItem = $this->products->resolveOrderItem(
                        $productId,
                        $item->product_variant_id,
                        $quantity
                    );
                } catch (\Exception $e) {
                    return $this->errorResponse($e->getMessage(), 400);
                }

                $data = $item->data ?? [];
                $data['name'] = $resolvedItem['name'];
                $data['price'] = $resolvedItem['price'];
                $data['attributes'] = $resolvedItem['attributes'];

                $item->update([
                    'quantity' => $resolvedItem['quantity'],
                    'data' => $data,
                ]);
            }
        }

        return $this->index($request);
    }

    public function remove(Request $request)
    {
        $request->validate([
            'product_id' => 'required',
            'variant_id' => 'nullable',
        ]);

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
