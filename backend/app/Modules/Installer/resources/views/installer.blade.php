<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Cài đặt SiteX Post</title>
    <link rel="stylesheet" href="{{ asset('installer/installer.css') }}?v={{ $version }}">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
<div class="mx-auto flex min-h-screen max-w-5xl flex-col px-4 py-6 sm:py-10">
    <header class="mb-6 flex items-center gap-3">
        <svg class="h-10 w-10 shrink-0" viewBox="0 0 40 40" aria-hidden="true"><rect width="40" height="40" rx="10" fill="#2553eb"/><path d="M12 25.5c1.8 1.6 4.3 2.5 7 2.5 4 0 7-1.9 7-5 0-2.8-2.3-4-6-4.6-2.6-.4-3.6-.9-3.6-1.9 0-1 1.1-1.7 2.9-1.7 1.9 0 3.6.7 4.9 1.8l1.9-2.7C24.4 12.7 22.2 12 19.9 12c-3.9 0-6.7 2-6.7 5 0 2.9 2.3 4 6 4.6 2.6.4 3.5.9 3.5 1.9 0 1.1-1.3 1.8-3.3 1.8-2.2 0-4.2-.8-5.7-2.2z" fill="#fff"/></svg>
        <div>
            <p class="text-lg font-bold leading-tight">SiteX Post</p>
            <p class="text-xs text-slate-500">Trình cài đặt · phiên bản {{ $version }}</p>
        </div>
    </header>

    @if ($installed)
        <main class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10" data-testid="installer-locked">
            <h1 class="text-2xl font-bold">SiteX Post đã được cài đặt</h1>
            <p class="mt-3 text-slate-600">Trình cài đặt đã bị khóa và không thể chạy lại. Để nâng cấp, dùng <strong>Nâng cấp hệ thống</strong> trong trang quản trị; để thay đổi cấu hình, dùng <strong>Thiết lập hệ thống</strong>.</p>
            <a class="mt-6 inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" href="{{ $adminUrl }}">Mở trang quản trị</a>
        </main>
    @else
        <div class="grid gap-6 lg:grid-cols-[230px_1fr]">
            <nav aria-label="Các bước cài đặt" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <ol id="steps" class="space-y-1 text-sm"></ol>
            </nav>
            <main id="app" class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8" aria-live="polite" data-token-file="{{ $tokenFile }}" data-deployment="{{ $deployment }}">
                <p class="text-slate-500">Đang tải trình cài đặt… (cần bật JavaScript)</p>
            </main>
        </div>
    @endif
    <footer class="mt-auto pt-8 text-center text-xs text-slate-400">SiteX Post Cloud · Không chia sẻ mã cài đặt với người khác.</footer>
</div>
@unless ($installed)
<script src="{{ asset('installer/installer.js') }}?v={{ $version }}" defer></script>
@endunless
</body>
</html>
