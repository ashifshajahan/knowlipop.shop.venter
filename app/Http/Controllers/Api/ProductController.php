<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['products' => Product::query()
            ->where('is_active', true)->latest()->paginate(20)]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['product' => Product::query()
            ->where('is_active', true)->findOrFail($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $product = $request->user()->products()->create($validated + ['is_active' => true]);
        return response()->json(['message' => 'Product created.', 'product' => $product], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $product = Product::query()->where('seller_id', $request->user()->id)->findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $product->update($validated);
        return response()->json(['message' => 'Product updated.', 'product' => $product->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $product = Product::query()->where('seller_id', $request->user()->id)->findOrFail($id);
        $product->delete();
        return response()->json(['message' => 'Product deleted.']);
    }
}
