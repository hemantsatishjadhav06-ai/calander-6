<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blogs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blogs\ReviewBlogDraftRequest;
use App\Http\Requests\Blogs\SaveBlogDraftRequest;
use App\Models\BlogDraft;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Blogs\BlogDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlogDraftController extends Controller
{
    public function index(Request $request, BlogDraftService $drafts): Response
    {
        $workspace = $drafts->workspace($request->user());
        $destination = $drafts->destination($workspace->id);
        $blogs = BlogDraft::query()->where('workspace_id', $workspace->id)
            ->latest('updated_at')->orderByDesc('id')->paginate(20)
            ->through(fn (BlogDraft $blog): array => $drafts->toView($blog, $request->user(), $workspace, $destination, false));

        return Inertia::render('blogs/index', ['blogs' => $blogs, ...$this->context($workspace, $destination)]);
    }

    public function create(Request $request, BlogDraftService $drafts): Response
    {
        $workspace = $drafts->workspace($request->user());

        return Inertia::render('blogs/edit', ['blog' => null, ...$this->context($workspace, $drafts->destination($workspace->id))]);
    }

    public function store(SaveBlogDraftRequest $request, BlogDraftService $drafts): RedirectResponse
    {
        $blog = $drafts->create($request->user(), $request->validated());

        return to_route('blogs.edit', $blog)->with('success', 'Private blog draft saved.');
    }

    public function edit(Request $request, BlogDraft $blogDraft, BlogDraftService $drafts): Response
    {
        return $this->show($request->user(), $blogDraft, $drafts, 'blogs/edit');
    }

    public function preview(Request $request, BlogDraft $blogDraft, BlogDraftService $drafts): Response
    {
        return $this->show($request->user(), $blogDraft, $drafts, 'blogs/preview');
    }

    public function update(SaveBlogDraftRequest $request, BlogDraft $blogDraft, BlogDraftService $drafts): RedirectResponse
    {
        $drafts->update($request->user(), $blogDraft, $request->validated(), $request->validated('revision'));

        return to_route('blogs.edit', $blogDraft)->with('success', 'Draft saved. Content changes require fresh approval.');
    }

    public function requestReview(ReviewBlogDraftRequest $request, BlogDraft $blogDraft, BlogDraftService $drafts): RedirectResponse
    {
        $drafts->review($request->user(), $blogDraft, 'request', $request->validated('revision'));

        return to_route('blogs.preview', $blogDraft)->with('success', 'Blog draft is awaiting your approval.');
    }

    public function approve(ReviewBlogDraftRequest $request, BlogDraft $blogDraft, BlogDraftService $drafts): RedirectResponse
    {
        $drafts->review($request->user(), $blogDraft, 'approve', $request->validated('revision'));

        return to_route('blogs.preview', $blogDraft)->with('success', 'This version is approved. Website publishing is not connected yet.');
    }

    public function reject(ReviewBlogDraftRequest $request, BlogDraft $blogDraft, BlogDraftService $drafts): RedirectResponse
    {
        $drafts->review($request->user(), $blogDraft, 'reject', $request->validated('revision'), $request->validated('reason'));

        return to_route('blogs.preview', $blogDraft)->with('success', 'Blog draft returned for changes.');
    }

    private function show(User $user, BlogDraft $blogDraft, BlogDraftService $drafts, string $page): Response
    {
        $workspace = $drafts->workspace($user);
        abort_unless($blogDraft->workspace_id === $workspace->id, 404);
        $destination = $drafts->destination($workspace->id);

        return Inertia::render($page, [
            'blog' => $drafts->toView($blogDraft, $user, $workspace, $destination),
            ...$this->context($workspace, $destination),
        ]);
    }

    /**
     * @param  array<string, string|null>  $destination
     * @return array<string, mixed>
     */
    private function context(Workspace $workspace, array $destination): array
    {
        return [
            'brand' => ['name' => $workspace->name, 'website_url' => $destination['website_url']],
            'publication' => [
                'available' => false,
                'reason' => 'Website publishing needs a verified site connection. You can save, preview, and approve private drafts now.',
            ],
        ];
    }
}
