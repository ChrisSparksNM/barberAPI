<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Taos Empire Barber Shop - Dashboard')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        gray: {
                            750: '#374151',
                            850: '#1f2937',
                            950: '#111827'
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-900 text-white min-h-screen">
    <!-- Navigation -->
    <nav class="bg-gray-800 border-b border-gray-700 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <h1 class="text-xl font-bold text-white">Taos Empire Barber Shop</h1>
                </div>
                
                @auth
                <div class="flex items-center space-x-4" style="position: relative; z-index: 1000;">
                    <div class="text-right">
                        <div class="text-gray-300 font-medium">{{ Auth::user()->name }}</div>
                        <div class="text-gray-400 text-xs">
                            @if(Auth::user()->isAdmin())
                                Administrator
                            @elseif(Auth::user()->isBarber())
                                Barber ({{ Auth::user()->barber_name }})
                            @else
                                Staff
                            @endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="inline" style="position: relative; z-index: 1001;">
                        @csrf
                        <button type="submit" class="bg-red-600 hover:bg-red-700 px-4 py-2 rounded-lg text-sm font-medium transition-colors" style="position: relative; z-index: 1002;">
                            Logout
                        </button>
                    </form>
                </div>
                @else
                <div class="text-gray-300">Not authenticated</div>
                @endauth
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <!-- Flash Messages -->
        @if(session('success'))
            <div class="mb-6 bg-green-600 border border-green-500 text-white px-4 py-3 rounded-lg">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-6 bg-red-600 border border-red-500 text-white px-4 py-3 rounded-lg">
                {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-6 bg-red-600 border border-red-500 text-white px-4 py-3 rounded-lg">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

    <script>
        // Auto-hide flash messages after 5 seconds (only target flash message containers)
        setTimeout(function() {
            const alerts = document.querySelectorAll('main > .bg-green-600, main > .bg-red-600');
            console.log('Auto-hiding flash messages:', alerts.length);
            alerts.forEach(alert => {
                console.log('Hiding flash message:', alert);
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                        console.log('Removed flash message');
                    }
                }, 500);
            });
        }, 5000);
    </script>
    
    @yield('scripts')
</body>
</html>