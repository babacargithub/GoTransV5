<?php

namespace App\Http\Controllers;

use App\Models\Depart;
use App\Models\Trajet;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class SeoFilesController extends Controller
{
    /**
     * @var list<string>
     */
    private const STATIC_PAGE_ROUTE_NAMES = ['website.yobante', 'website.aide'];

    /**
     * Served on demand (through CachePublicHtmlResponse, so it is cached and invalidated together
     * with the pages it lists) rather than written to a file: a file would go stale between
     * generations and public/ is shared by every domain the app serves.
     *
     * Only <loc> and an accurate <lastmod> are emitted — Google ignores <changefreq>/<priority>
     * and distrusts a <lastmod> that is not truthful. A caravane page's content is its upcoming
     * départs, so its lastmod is the latest change among them; static pages get none rather than
     * an invented date. Beyond 50,000 URLs this would become a sitemap index.
     */
    public function publicWebsiteSitemap(): Response
    {
        $latestDepartChangeByTrajetId = Depart::query()
            ->withoutGlobalScope('notCanceled')
            ->where(fn ($query) => $query->where('canceled', false)->orWhereNull('canceled'))
            ->where('date', '>=', now())
            ->groupBy('trajet_id')
            ->selectRaw('trajet_id, MAX(updated_at) as latest_change')
            ->pluck('latest_change', 'trajet_id');

        $caravaneEntries = Trajet::query()
            ->publiclyVisible()
            ->get(['id', 'slug', 'name', 'display_position'])
            ->map(fn (Trajet $trajet): array => [
                'url' => route('website.caravanes.show', ['trajet' => $trajet->slug]),
                'lastModified' => $this->formatLastModified($latestDepartChangeByTrajetId->get($trajet->id)),
            ]);

        $homeEntry = [
            'url' => route('website.home'),
            'lastModified' => $this->formatLastModified($latestDepartChangeByTrajetId->max()),
        ];

        $staticEntries = collect(self::STATIC_PAGE_ROUTE_NAMES)
            ->map(fn (string $routeName): array => ['url' => route($routeName), 'lastModified' => null]);

        return response()
            ->view('website.sitemap', [
                'sitemapEntries' => collect([$homeEntry])->concat($caravaneEntries)->concat($staticEntries),
            ])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function publicWebsiteRobots(): Response
    {
        $robotsLines = [
            'User-agent: *',
            'Disallow: /reserver/',
            'Disallow: /reservations/',
            'Disallow: /tickets/',
            'Disallow: /caravanes/horaires/',
            '',
            'Sitemap: '.route('website.sitemap'),
        ];

        return $this->plainTextResponse($robotsLines);
    }

    /**
     * Every other host (the back office, whose pages are also noindex) is kept out of search
     * engines, except the standalone landing domains which stay fully crawlable.
     */
    public function fallbackRobots(Request $request): Response
    {
        $isCrawlableLandingDomain = in_array($request->getHost(), [
            config('app.concours_domain'),
            config('app.gp_domain'),
        ], true);

        return $this->plainTextResponse([
            'User-agent: *',
            $isCrawlableLandingDomain ? 'Disallow:' : 'Disallow: /',
        ]);
    }

    private function formatLastModified(?string $databaseTimestamp): ?string
    {
        return $databaseTimestamp === null ? null : Carbon::parse($databaseTimestamp)->toAtomString();
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function plainTextResponse(array $lines): Response
    {
        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
