<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $blog->seo_title ?: $blog->title }}</title>
    <meta name="description" content="{{ $blog->seo_description ?: $blog->excerpt }}">
    <link rel="canonical" href="{{ $blog->canonical_url ?: $url }}">
    <link rel="stylesheet" href="{{ $cssPath }}">
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $blog->title }}">
    <meta property="og:description" content="{{ $blog->seo_description ?: $blog->excerpt }}">
    <meta property="og:url" content="{{ $url }}">
</head>
<body>
    <!-- sm-manager-blog:{{ $blog->id }} -->
    <header><a href="{{ $website }}/">{{ parse_url($website, PHP_URL_HOST) }}</a></header>
    <main>
        <article>
            <h1>{{ $blog->title }}</h1>
            @if ($blog->excerpt)
                <p class="excerpt">{{ $blog->excerpt }}</p>
            @endif
            @if ($blog->featured_image_url)
                <img src="{{ $blog->featured_image_url }}" alt="{{ $blog->featured_image_alt }}" loading="lazy" referrerpolicy="no-referrer">
            @endif
            <div class="article-body">{{ $blog->body }}</div>
        </article>
        <footer><a href="{{ $website }}/">Return to the website</a></footer>
    </main>
</body>
</html>
