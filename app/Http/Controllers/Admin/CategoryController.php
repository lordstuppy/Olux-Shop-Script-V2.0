<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        return view('admin.categories', ['categories' => Category::withCount('products')->orderBy('name')->get()]);
    }

    public function update(Request $request, Category $category, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('categories', 'name')->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $category->update($data);
        $audit->log('category.updated', $category);

        return back()->with('success', "Category \"{$category->name}\" updated. Its address (/{$category->slug}) stays the same.");
    }

    public function destroy(Category $category, AuditLogger $audit): RedirectResponse
    {
        if ($category->products()->exists()) {
            throw new UserFacingException("Category \"{$category->name}\" still has products. Move them to another category first.");
        }
        $category->delete();
        $audit->log('category.deleted', null, ['name' => $category->name]);

        return back()->with('success', "Category \"{$category->name}\" deleted.");
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:categories,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $category = Category::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']) ?: Str::lower(Str::random(8)),
            'description' => $data['description'] ?? null,
        ]);
        $audit->log('category.created', $category);

        return back()->with('success', "Category \"{$category->name}\" created.");
    }
}
