<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#122c3b">
    @vite('resources/js/app.js')
    @inertiaHead
</head>
<body>@inertia</body>
</html>
