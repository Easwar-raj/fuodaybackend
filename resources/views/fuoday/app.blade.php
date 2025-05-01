<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Fuoday')</title>
    <meta name="description"
        content="A team is not a group of people who work together. A team is a group of people who trust each other.">
    <meta name="keywords" content="Fuoday">

    {{-- Favicon --}}
    <link type="image/x-icon" rel="icon" href="{{ asset('assets/img/favicon/favicon.ico') }}">
    <link href="{{ asset('assets/img/favicon/apple-touch-icon.png') }}" rel="apple-touch-icon">

    {{-- Bootstrap --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

    {{-- Style --}}
    <link rel="stylesheet" href="{{ asset('css/fuoday/homepage.css') }}">


    {{-- Angular JS CDN Link --}}
    <script src="https://ajax.googleapis.com/ajax/libs/angularjs/1.8.3/angular.min.js"></script>
    {{-- Vendor --}}


</head>

<body ng-app="fuoday" class="index-page">

    <div ng-controller="FuodayController">
        @yield('content')
    </div>

    <!-- Scroll Top -->
    <a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center"><i
            class="bi bi-arrow-up-short"></i></a>

    <!-- Preloader -->
    <div id="preloader"></div>

    <!-- Vendor JS Files -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" integrity="sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy" crossorigin="anonymous"></script>
    {{-- JS Files --}}
    <script src="{{ asset('js/fuoday/app.js') }}"></script>
</body>

</html>
