<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
{{--    use tailwind--}}
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

</head>
<body class="bg-light">

<main class="container my-5">
    <div class="row gy-4">
        @foreach ($departs as $depart)
            <div class="col-md-6">
                <div class="card shadow-sm border-0 h-100">
                    <a href="https://laracasts.com" class="text-decoration-none text-dark d-flex p-4 align-items-start">
                        <div class="me-3 rounded-circle bg-blue-500 d-flex align-items-center justify-content-center"
                             style="width: 50px; height: 50px;">
                            <!-- Icon (replace with SVG or Font Awesome icon if needed) -->
                            <i class="bi bi-bus-front-fill text-white"></i>
                        </div>
                        <div>
                            <h5 class="card-title font-bold">{{ $depart->name }}</h5>
                            <p class="text-sm text-muted ">{{$depart->start_point}}--{{$depart->end_point}}</p>
                        </div>
                    </a>
                </div>
            </div>
        @endforeach
    </div>
</main>

<footer class="text-center py-4 bg-white text-muted">
    Laravel v{{ $laravelVersion }} (PHP v{{ $phpVersion }})
</footer>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
{{--use--}}
</body>
</html>
