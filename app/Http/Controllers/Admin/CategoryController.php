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

        return back()->with('success', __('Category ":name" updated. Its address (/:slug) stays the same.', ['name' => $category->name, 'slug' => $category->slug]));
    }

    public function destroy(Category $category, AuditLogger $audit): RedirectResponse
    {
        if ($category->products()->exists()) {
            throw new UserFacingException(__('Category ":name" still has products. Move them to another category first.', ['name' => $category->name]));
        }
        $category->delete();
        $audit->log('category.deleted', null, ['name' => $category->name]);

        return back()->with('success', __('Category ":name" deleted.', ['name' => $category->name]));
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

        return back()->with('success', __('Category ":name" created.', ['name' => $category->name]));
    }
}
