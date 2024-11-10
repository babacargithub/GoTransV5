<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Default Title')</title>

    <!-- Styles and meta tags -->
    @yield('head')  <!-- For additional head content from child views -->

</head>
<body class="bg-gray-100 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
<!-- Header -->


<!-- Main content -->
<main>
    @yield('content')  <!-- Main content section for child views -->
</main>

<!-- Sidebar (optional) -->
@yield('sidebar')

<!-- Footer -->

<!-- Additional Scripts -->
@yield('scripts')  <!-- For additional scripts from child views -->
</body>
</html>
