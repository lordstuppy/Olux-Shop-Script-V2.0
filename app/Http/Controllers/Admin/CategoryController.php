<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        return view('admin.categories', ['categories' => Category::withCount('products')->orderBy('name')->get()]);
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
