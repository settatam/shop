<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()->with(['parent', 'children']);

        if ($request->boolean('roots_only')) {
            $query->roots();
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        $categories = $request->boolean('all')
            ? $query->orderBy('sort_order')->get()
            : $query->orderBy('sort_order')->paginate($request->input('per_page', 15));

        return response()->json($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json($category, 201);
    }

    public function show(Category $category): JsonResponse
    {
        $category->load(['parent', 'children', 'products']);

        return response()->json($category);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $category->update($request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'slug' => ['sometimes', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'template_id' => ['nullable', 'integer', 'exists:product_templates,id'],
            'sort_order' => ['nullable', 'integer'],
            'meta_title' => ['nullable', 'string', 'max:191'],
            'meta_description' => ['nullable', 'string', 'max:191'],
        ]));

        return response()->json($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();

        return response()->json(null, 204);
    }

    public function tree(): JsonResponse
    {
        $categories = Category::roots()
            ->with(['children' => function ($query) {
                $query->with(['children' => function ($q) {
                    $q->with('children')->orderBy('sort_order');
                }])->orderBy('sort_order');
            }])
            ->orderBy('sort_order')
            ->get();

        return response()->json($categories);
    }

    public function flat(): JsonResponse
    {
        $categories = Category::orderBy('level')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'full_path' => $category->full_path,
                    'parent_id' => $category->parent_id,
                    'level' => $category->level,
                ];
            });

        return response()->json($categories);
    }

    public function ancestors(Category $category): JsonResponse
    {
        $ancestors = collect();
        $current = $category;

        while ($current->parent) {
            $ancestors->prepend($current->parent);
            $current = $current->parent;
        }

        return response()->json($ancestors);
    }

    public function descendants(Category $category): JsonResponse
    {
        $category->load(['children' => function ($query) {
            $query->with(['children' => function ($q) {
                $q->with('children')->orderBy('sort_order');
            }])->orderBy('sort_order');
        }]);

        return response()->json($category->children);
    }

    public function template(Category $category): JsonResponse
    {
        $template = $category->getEffectiveTemplate();

        if (! $template) {
            return response()->json(['message' => 'No template assigned to this category or its parents'], 404);
        }

        $template->load('fields.options');

        return response()->json($template);
    }
}
