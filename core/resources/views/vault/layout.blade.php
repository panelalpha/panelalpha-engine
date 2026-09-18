{{--
    Root-relative URLs throughout: the host's nginx forwards a Host header with
    no port and no scheme, so `asset()` would resolve to a URL that 404s.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'PanelAlpha Engine' }}</title>
    @include('partials.favicons')
    <link rel="stylesheet" href="/vault/vault.css">
</head>
<body>
    <div class="page">
        <div class="glow" aria-hidden="true"></div>
        <img class="wordmark" src="/vault/pa-engine-lockup.svg" alt="PanelAlpha Engine" width="289" height="64">
        @yield('card')
    </div>
    @stack('scripts')
</body>
</html>
