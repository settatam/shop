<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHelpArticleRequest;
use App\Http\Requests\UpdateHelpArticleRequest;
use App\Models\HelpArticle;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class HelpArticleController extends Controller
{
    public function index(): Response
    {
        $articles = HelpArticle::query()
            ->published()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('help/Index', [
            'articles' => $articles,
            'categories' => HelpArticle::CATEGORIES,
        ]);
    }

    public function show(string $slug): Response
    {
        $article = HelpArticle::where('slug', $slug)
            ->published()
            ->firstOrFail();

        $allArticles = HelpArticle::query()
            ->published()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category');

        // Find previous and next articles within the same category
        $categoryArticles = HelpArticle::query()
            ->published()
            ->where('category', $article->category)
            ->orderBy('sort_order')
            ->get();

        $currentIndex = $categoryArticles->search(fn (HelpArticle $a) => $a->id === $article->id);
        $previous = $currentIndex > 0 ? $categoryArticles[$currentIndex - 1] : null;
        $next = $currentIndex < $categoryArticles->count() - 1 ? $categoryArticles[$currentIndex + 1] : null;

        return Inertia::render('help/Show', [
            'article' => $article,
            'allArticles' => $allArticles,
            'previous' => $previous ? ['title' => $previous->title, 'slug' => $previous->slug] : null,
            'next' => $next ? ['title' => $next->title, 'slug' => $next->slug] : null,
        ]);
    }

    public function admin(): Response
    {
        $articles = HelpArticle::query()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('settings/HelpArticles', [
            'articles' => $articles,
            'categories' => HelpArticle::CATEGORIES,
        ]);
    }

    public function store(StoreHelpArticleRequest $request): RedirectResponse
    {
        $maxSortOrder = HelpArticle::where('category', $request->category)
            ->max('sort_order') ?? -1;

        HelpArticle::create([
            'category' => $request->category,
            'title' => $request->title,
            'content' => $request->content,
            'excerpt' => $request->excerpt,
            'sort_order' => $maxSortOrder + 1,
            'is_published' => $request->boolean('is_published', true),
        ]);

        return back()->with('success', 'Help article created.');
    }

    public function update(UpdateHelpArticleRequest $request, HelpArticle $article): RedirectResponse
    {
        $article->update([
            'category' => $request->category,
            'title' => $request->title,
            'content' => $request->content,
            'excerpt' => $request->excerpt,
            'is_published' => $request->boolean('is_published', $article->is_published),
        ]);

        return back()->with('success', 'Help article updated.');
    }

    public function destroy(HelpArticle $article): RedirectResponse
    {
        $article->delete();

        return back()->with('success', 'Help article deleted.');
    }
}
