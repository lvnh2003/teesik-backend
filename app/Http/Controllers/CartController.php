<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /**
     * Get user's cart items
     */
    public function index(User $user): JsonResponse
    {
        try {
            $cartItems = Cart::with(['product', 'variant'])
                ->where('user_id', $user->id)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'productId' => $item->product_id,
                        'name' => $item->product->name,
                        'quantity' => $item->quantity,
                        'variant' => $item->variant ? [
                            'id' => $item->variant->id,
                            'sku' => $item->variant->sku,
                            'attributes' => $item->variant->attributes ?? [],
                            'image' => $item->variant->image->image_path ? asset('storage/' . $item->variant->image->image_path) : null,
                            'price' => $item->variant->price,
                        ] : null,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $cartItems,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cart items',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add item to cart
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'variant_id' => 'nullable|exists:product_variants,id',
                'quantity' => 'required|integer|min:1',
                'price' => 'required|numeric|min:0',
            ]);

            $user = Auth::user();
            
            // Check if item already exists in cart
            $existingItem = Cart::where('user_id', $user->id)
                ->where('product_id', $request->product_id)
                ->where('variant_id', $request->variant_id)
                ->first();

            if ($existingItem) {
                // Update quantity
                $existingItem->quantity += $request->quantity;
                $existingItem->save();
                $cartItem = $existingItem;
            } else {
                // Create new cart item
                $cartItem = Cart::create([
                    'user_id' => $user->id,
                    'product_id' => $request->product_id,
                    'variant_id' => $request->variant_id,
                    'quantity' => $request->quantity,
                    'price' => $request->price,
                ]);
            }

            // Load relationships
            $cartItem->load(['product', 'variant']);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $cartItem->id,
                    'productId' => $cartItem->product_id,
                    'name' => $cartItem->product->name,
                    'price' => $cartItem->price,
                    'originalPrice' => $cartItem->product->original_price,
                    'image' => $cartItem->product->main_image ? asset('storage/' . $cartItem->product->main_image->image_path) : null,
                    'quantity' => $cartItem->quantity,
                    'variant' => $cartItem->variant ? [
                        'id' => $cartItem->variant->id,
                        'sku' => $cartItem->variant->sku,
                        'attributes' => $cartItem->variant->attributes ?? [],
                    ] : null,
                ],
                'message' => 'Item added to cart successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add item to cart',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update cart item quantity
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $request->validate([
                'quantity' => 'required|integer|min:1',
            ]);

            $user = Auth::user();
            
            $cartItem = Cart::where('user_id', $user->id)
                ->where('id', $id)
                ->firstOrFail();

            $cartItem->quantity = $request->quantity;
            $cartItem->save();

            return response()->json([
                'success' => true,
                'message' => 'Cart item updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update cart item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove item from cart
     */
    public function destroy($id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $cartItem = Cart::where('user_id', $user->id)
                ->where('id', $id)
                ->firstOrFail();

            $cartItem->delete();

            return response()->json([
                'success' => true,
                'message' => 'Item removed from cart successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove item from cart',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear entire cart
     */
    public function clear(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            Cart::where('user_id', $user->id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Cart cleared successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cart',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync local cart to server
     */
    public function sync(Request $request, User $user): JsonResponse
    {
        try {
            $request->validate([
                'items' => 'required|array',
                'items.*.productId' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.price' => 'required|numeric|min:0',
                'items.*.variant.id' => 'nullable|exists:product_variants,id',
            ]);

            
            DB::beginTransaction();

            foreach ($request->items as $item) {
                // Check if item already exists in server cart
                $existingItem = Cart::where('user_id', $user->id)
                    ->where('product_id', $item['productId'])
                    ->where('variant_id', $item['variant']['id'] ?? null)
                    ->first();

                if ($existingItem) {
                    // Update quantity (add to existing)
                    $existingItem->quantity += $item['quantity'];
                    $existingItem->save();
                } else {
                    // Create new cart item
                    Cart::create([
                        'user_id' => $user->id,
                        'product_id' => $item['productId'],
                        'variant_id' => $item['variant']['id'] ?? null,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cart synced successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync cart',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
