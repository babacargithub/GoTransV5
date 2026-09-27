{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($sitemapEntries as $sitemapEntry)
    <url>
        <loc>{{ $sitemapEntry['url'] }}</loc>
@if ($sitemapEntry['lastModified'])
        <lastmod>{{ $sitemapEntry['lastModified'] }}</lastmod>
@endif
    </url>
@endforeach
</urlset>
