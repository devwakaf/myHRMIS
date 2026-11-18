<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="utf-8">
<title>@yield('title')</title>
<style>
    @page { margin: 28px 32px 36px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1a1a1a; }
    h1 { font-size: 15px; margin: 0 0 2px 0; letter-spacing: 1px; }
    h2 { font-size: 10px; margin: 0 0 4px 0; padding-bottom: 2px; border-bottom: 1px solid #888; text-transform: uppercase; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 3px 4px; vertical-align: top; }
    th { text-align: left; background: #eeeeee; border-bottom: 1px solid #999; }
    .r { text-align: right; }
    .c { text-align: center; }
    .b { font-weight: bold; }
    .muted { color: #666; }
    .small { font-size: 8px; }
    .box { border: 1px solid #999; padding: 5px 6px; }
    .grid td { border-bottom: 1px solid #ddd; }
    .total td { border-top: 1px solid #333; font-weight: bold; }
    .net { background: #e8f1fb; font-size: 11px; font-weight: bold; border: 1px solid #5a86b8; }
    .ribbon { border: 2px solid #b00020; color: #b00020; font-weight: bold; text-align: center; padding: 3px; margin-bottom: 6px; letter-spacing: 2px; }
    .notice { border: 1px solid #c77700; background: #fff4e0; color: #7a4a00; padding: 4px 6px; margin-bottom: 6px; }
    .footer { margin-top: 10px; text-align: center; font-size: 7.5px; color: #666; }
    .head td { padding: 0; }
</style>
</head>
<body>
@yield('content')
</body>
</html>
