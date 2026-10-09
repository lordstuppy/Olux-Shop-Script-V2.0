<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public const CACHE_KEY = 'shop.announcements';

    public function index(): View
    {
        return view('admin.announcements', ['announcements' => Announcement::latest('id')->paginate(30)]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        $announcement = Announcement::create($data + ['active' => true, 'created_by' => $request->user()->id]);
        Cache::forget(self::CACHE_KEY);
        $audit->log('announcement.created', $announcement);

        return back()->with('success', __('Announcement ":title" published.', ['title' => $announcement->title]));
    }

    public function toggle(Announcement $announcement, AuditLogger $audit): RedirectResponse
    {
        $announcement->update(['active' => ! $announcement->active]);
        Cache::forget(self::CACHE_KEY);
        $audit->log($announcement->active ? 'announcement.activated' : 'announcement.deactivated', $announcement);

        return back()->with('success', $announcement->active ? __('Announcement ":title" is now visible.', ['title' => $announcement->title]) : __('Announcement ":title" is now hidden.', ['title' => $announcement->title]));
    }

    public function destroy(Announcement $announcement, AuditLogger $audit): RedirectResponse
    {
        $announcement->delete();
        Cache::forget(self::CACHE_KEY);
        $audit->log('announcement.deleted', null, ['title' => $announcement->title]);

        return back()->with('success', __('Announcement ":title" deleted.', ['title' => $announcement->title]));
    }
}
