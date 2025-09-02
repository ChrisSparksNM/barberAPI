@extends('layouts.app')

@section('title', 'Dashboard - Taos Empire Barber Shop')

@php
    // Helper function to safely format appointment time
    function formatAppointmentTime($appointmentTime) {
        try {
            $timeString = is_string($appointmentTime) 
                ? $appointmentTime 
                : $appointmentTime->format('H:i');
            
            // Handle different time formats - remove seconds if present
            if (strlen($timeString) > 5) {
                $timeString = substr($timeString, 0, 5);
            }
            
            return \Carbon\Carbon::createFromFormat('H:i', $timeString)->format('g:i A');
        } catch (Exception $e) {
            return $appointmentTime ?? 'Invalid Time';
        }
    }
@endphp

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-white">
                @if($user && $user->isBarber())
                    {{ $user->barber_name }}'s Appointments
                @elseif($user && $user->isAdmin())
                    Admin Dashboard
                @else
                    Appointment Dashboard
                @endif
            </h1>
            @if($user && $user->isBarber())
                <p class="text-gray-400 text-sm mt-1">Your personal appointment schedule</p>
            @elseif($user && $user->isAdmin())
                <p class="text-gray-400 text-sm mt-1">Manage all appointments and barbers</p>
            @endif
        </div>
        
        <!-- Admin Actions and Filters -->
        <div class="flex space-x-4">
            @if($user && $user->isAdmin())
                <form method="POST" action="{{ route('send-all-reminders') }}" class="inline">
                    @csrf
                    <button 
                        type="submit" 
                        class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                        onclick="return confirm('Send SMS reminders to all customers with appointments in the next 24 hours?')"
                        title="Send automated SMS reminders"
                    >
                        📱 Send All Reminders
                    </button>
                </form>
            @endif
            <form method="GET" action="{{ route('dashboard') }}" class="flex space-x-4" id="filterForm">
                <!-- View Mode Toggle -->
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">View</label>
                    <div class="flex bg-gray-800 rounded-lg border border-gray-600">
                        <button 
                            type="button" 
                            onclick="setViewMode('calendar')"
                            class="px-4 py-2 text-sm font-medium rounded-l-lg transition-colors {{ $viewMode === 'calendar' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:text-white hover:bg-gray-700' }}"
                        >
                            📅 Calendar
                        </button>
                        <button 
                            type="button" 
                            onclick="setViewMode('list')"
                            class="px-4 py-2 text-sm font-medium rounded-r-lg transition-colors {{ $viewMode === 'list' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:text-white hover:bg-gray-700' }}"
                        >
                            📋 List
                        </button>
                    </div>
                    <input type="hidden" name="view" id="viewInput" value="{{ $viewMode }}">
                </div>

                @if($viewMode === 'calendar')
                    <!-- Month Navigation -->
                    <div>
                        <label for="month" class="block text-sm font-medium text-gray-300 mb-1">Month</label>
                        <input 
                            type="month" 
                            id="month" 
                            name="month" 
                            value="{{ $currentMonth }}"
                            class="px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                            onchange="this.form.submit()"
                        >
                    </div>
                @else
                    <!-- Date Filter for List View -->
                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-300 mb-1">Date</label>
                        <input 
                            type="date" 
                            id="date" 
                            name="date" 
                            value="{{ $selectedDate }}"
                            class="px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                            onchange="this.form.submit()"
                        >
                    </div>
                @endif
                
                <!-- Barber Filter (only for admin) -->
                @if($user && $user->isAdmin())
                    <div>
                        <label for="barber" class="block text-sm font-medium text-gray-300 mb-1">Barber</label>
                        <select 
                            id="barber" 
                            name="barber" 
                            class="px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                            onchange="this.form.submit()"
                        >
                            <option value="all" {{ $selectedBarber === 'all' ? 'selected' : '' }}>All Barbers</option>
                            @foreach($barbers as $barber)
                                <option value="{{ $barber }}" {{ $selectedBarber === $barber ? 'selected' : '' }}>
                                    {{ $barber }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                        <span class="text-white text-sm font-bold">📅</span>
                    </div>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-400">Total Appointments</p>
                    <p class="text-2xl font-bold text-white">{{ $stats['total_appointments'] }}</p>
                </div>
            </div>
        </div>

        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-green-600 rounded-lg flex items-center justify-center">
                        <span class="text-white text-sm font-bold">✓</span>
                    </div>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-400">Completed</p>
                    <p class="text-2xl font-bold text-white">{{ $stats['completed'] }}</p>
                </div>
            </div>
        </div>

        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-red-600 rounded-lg flex items-center justify-center">
                        <span class="text-white text-sm font-bold">✗</span>
                    </div>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-400">No-Shows</p>
                    <p class="text-2xl font-bold text-white">{{ $stats['no_shows'] }}</p>
                </div>
            </div>
        </div>

        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-purple-600 rounded-lg flex items-center justify-center">
                        <span class="text-white text-sm font-bold">$</span>
                    </div>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-400">Revenue</p>
                    <p class="text-2xl font-bold text-white">${{ number_format($stats['total_revenue'], 2) }}</p>
                </div>
            </div>
        </div>

        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-green-600 rounded-lg flex items-center justify-center">
                        <span class="text-white text-sm font-bold">💰</span>
                    </div>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-400">Tips</p>
                    <p class="text-2xl font-bold text-white">${{ number_format($stats['total_tips'], 2) }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- No-Show Actions -->
    @if(isset($eligibleNoShows) && $eligibleNoShows->count() > 0)
        <div class="bg-amber-600/10 border border-amber-600/30 rounded-lg p-4" style="position: relative; z-index: 10;">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-amber-400">Eligible No-Shows</h3>
                    <p class="text-amber-300 text-sm">{{ $eligibleNoShows->count() }} appointments are eligible for no-show charges</p>
                </div>
                <form method="POST" action="{{ route('appointments.process-no-shows') }}" class="inline">
                    @csrf
                    <input type="hidden" name="date" value="{{ $selectedDate }}">
                    <button 
                        type="submit" 
                        class="bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded-lg font-medium transition-colors"
                        onclick="return confirm('Are you sure you want to process all eligible no-show charges?')"
                    >
                        Process All No-Shows
                    </button>
                </form>
            </div>
        </div>
    @endif

    <!-- Calendar or List View -->
    @if($viewMode === 'calendar')
        <!-- Monthly Calendar View -->
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-700">
                <h2 class="text-lg font-semibold text-white">
                    Calendar View - {{ $monthStart->format('F Y') }}
                    @if($user && $user->isAdmin() && $selectedBarber !== 'all')
                        - {{ $selectedBarber }}
                    @elseif($user && $user->isBarber())
                        - {{ $user->barber_name }}
                    @endif
                </h2>
            </div>

            <div class="p-6">
                <!-- Calendar Grid -->
                <div class="grid grid-cols-7 gap-1 mb-4">
                    <!-- Day Headers -->
                    @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day)
                        <div class="p-2 text-center text-sm font-medium text-gray-400 border-b border-gray-700">
                            {{ $day }}
                        </div>
                    @endforeach
                    
                    <!-- Calendar Days -->
                    @php
                        $startOfCalendar = $monthStart->copy()->startOfWeek();
                        $endOfCalendar = $monthEnd->copy()->endOfWeek();
                        $currentDate = $startOfCalendar->copy();
                    @endphp
                    
                    @while($currentDate <= $endOfCalendar)
                        @php
                            $dateString = $currentDate->format('Y-m-d');
                            $dayAppointments = $monthlyAppointments->get($dateString, collect());
                            $isCurrentMonth = $currentDate->month === $monthStart->month;
                            $isToday = $currentDate->isToday();
                            $appointmentCount = $dayAppointments->count();
                        @endphp
                        
                        <div class="relative min-h-[80px] p-2 border border-gray-700 {{ $isCurrentMonth ? 'bg-gray-800' : 'bg-gray-900' }} {{ $isToday ? 'ring-2 ring-blue-500' : '' }} hover:bg-gray-750 cursor-pointer transition-colors"
                             onclick="openDayModal('{{ $dateString }}')">
                            <!-- Day Number -->
                            <div class="text-sm font-medium {{ $isCurrentMonth ? 'text-white' : 'text-gray-500' }} {{ $isToday ? 'text-blue-400' : '' }}">
                                {{ $currentDate->day }}
                            </div>
                            
                            <!-- Appointment Indicators -->
                            @if($appointmentCount > 0)
                                <div class="mt-1 space-y-1">
                                    @foreach($dayAppointments->take(3) as $appointment)
                                        <div class="text-xs px-1 py-0.5 rounded truncate
                                            @if($appointment->is_no_show) bg-red-600 text-white
                                            @elseif($appointment->appointment_status === 'completed') bg-green-600 text-white
                                            @elseif($appointment->appointment_status === 'cancelled') bg-gray-600 text-white
                                            @else bg-blue-600 text-white
                                            @endif">
                                            {{ formatAppointmentTime($appointment->appointment_time) }}
                                        </div>
                                    @endforeach
                                    
                                    @if($appointmentCount > 3)
                                        <div class="text-xs text-gray-400">
                                            +{{ $appointmentCount - 3 }} more
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                        
                        @php $currentDate->addDay(); @endphp
                    @endwhile
                </div>
            </div>
        </div>
    @else
        <!-- List View -->
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-700">
                <h2 class="text-lg font-semibold text-white">
                    Appointments for {{ \Carbon\Carbon::parse($selectedDate)->format('l, F j, Y') }}
                    @if($user && $user->isAdmin() && $selectedBarber !== 'all')
                        - {{ $selectedBarber }}
                    @elseif($user && $user->isBarber())
                        - {{ $user->barber_name }}
                    @endif
                </h2>
            </div>

            @if($appointments->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead class="bg-gray-750">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Customer</th>
                                @if($user && $user->isAdmin())
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Barber</th>
                                @endif
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Services</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-gray-800 divide-y divide-gray-700">
                            @foreach($appointments as $appointment)
                                <tr class="hover:bg-gray-750">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-white">
                                        {{ formatAppointmentTime($appointment->appointment_time) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-white">{{ $appointment->user->name }}</div>
                                        <div class="text-sm text-gray-400">{{ $appointment->user->email }}</div>
                                    </td>
                                    @if($user && $user->isAdmin())
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-white">
                                            {{ $appointment->barber_name }}
                                        </td>
                                    @endif
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-white">
                                            @if($appointment->services)
                                                @foreach($appointment->services as $service)
                                                    <div class="mb-1">
                                                        {{ $service['name'] }} - ${{ number_format($service['price'], 2) }}
                                                    </div>
                                                @endforeach
                                            @else
                                                <span class="text-gray-400">No services listed</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-white">${{ number_format($appointment->total_amount, 2) }}</div>
                                        @if($appointment->tip_amount > 0)
                                            <div class="text-sm text-green-400">Tip: ${{ number_format($appointment->tip_amount, 2) }}</div>
                                        @endif
                                        @if($appointment->is_no_show && $appointment->no_show_charge_amount)
                                            <div class="text-sm text-red-400">No-show: ${{ number_format($appointment->no_show_charge_amount, 2) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($appointment->is_no_show)
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-600 text-white">
                                                No-Show
                                            </span>
                                        @elseif($appointment->appointment_status === 'completed')
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-600 text-white">
                                                Completed
                                            </span>
                                        @elseif($appointment->appointment_status === 'cancelled')
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-600 text-white">
                                                Cancelled
                                            </span>
                                        @else
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-600 text-white">
                                                Scheduled
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                        @if($appointment->appointment_status === 'scheduled' && !$appointment->is_no_show)
                                            <!-- Mark Completed (with remaining balance charge if applicable) -->
                                            <form method="POST" action="{{ route('appointments.mark-completed', $appointment) }}" class="inline">
                                                @csrf
                                                <button 
                                                    type="submit" 
                                                    class="text-green-400 hover:text-green-300 text-xs bg-green-600/20 hover:bg-green-600/30 px-2 py-1 rounded transition-colors"
                                                    @if($appointment->hasRemainingBalance())
                                                        onclick="return confirm('Mark as completed and charge remaining balance of ${{ number_format($appointment->remaining_amount, 2) }}?')"
                                                        title="Mark as completed and charge remaining balance"
                                                    @else
                                                        title="Mark as completed"
                                                    @endif
                                                >
                                                    ✓ Complete
                                                    @if($appointment->hasRemainingBalance())
                                                        & Charge
                                                    @endif
                                                </button>
                                            </form>

                                            <!-- Mark No-Show & Charge (available for all scheduled appointments) -->
                                            <form method="POST" action="{{ route('appointments.mark-no-show', $appointment) }}" class="inline">
                                                @csrf
                                                <button 
                                                    type="submit" 
                                                    class="text-red-400 hover:text-red-300 text-xs bg-red-600/20 hover:bg-red-600/30 px-2 py-1 rounded transition-colors"
                                                    onclick="return confirm('Mark as no-show and charge full service amount (${{ number_format($appointment->total_amount, 2) }})?')"
                                                    title="Mark as no-show and charge full service amount"
                                                >
                                                    &#10060; No-Show & Charge
                                                </button>
                                            </form>
                                        @elseif($appointment->is_no_show && !$appointment->no_show_charge_amount && $appointment->user->defaultPaymentMethod)
                                            <!-- Charge No-Show (for existing no-shows without charge) -->
                                            <form method="POST" action="{{ route('appointments.charge-no-show', $appointment) }}" class="inline">
                                                @csrf
                                                <button 
                                                    type="submit" 
                                                    class="text-yellow-400 hover:text-yellow-300 text-xs bg-yellow-600/20 hover:bg-yellow-600/30 px-2 py-1 rounded transition-colors"
                                                    onclick="return confirm('Charge no-show fee of ${{ number_format($appointment->total_amount, 2) }}?')"
                                                    title="Charge the full service amount for this no-show"
                                                >
                                                    &#128176; Charge No-Show
                                                </button>
                                            </form>
                                        @elseif($appointment->appointment_status === 'completed' && $appointment->hasRemainingBalance() && $appointment->user->defaultPaymentMethod)
                                            <!-- Charge Remaining Balance (for completed appointments with remaining balance) -->
                                            <form method="POST" action="{{ route('appointments.charge-remaining', $appointment) }}" class="inline">
                                                @csrf
                                                <button 
                                                    type="submit" 
                                                    class="text-blue-400 hover:text-blue-300 text-xs bg-blue-600/20 hover:bg-blue-600/30 px-2 py-1 rounded transition-colors"
                                                    onclick="return confirm('Charge remaining balance of ${{ number_format($appointment->remaining_amount, 2) }}?')"
                                                    title="Charge the remaining balance for this completed appointment"
                                                >
                                                    &#128176; Charge Balance
                                                </button>
                                            </form>
                                        @endif
                                        
                                        <!-- Send Reminder Button (for all appointments that aren't cancelled) -->
                                        @if($appointment->appointment_status !== 'cancelled')
                                            <button 
                                                onclick="sendReminder({{ $appointment->id }}, '{{ $appointment->user->name }}', '{{ $appointment->barber_name }}', '{{ $appointment->appointment_date->format('l, F j, Y') }}', '{{ formatAppointmentTime($appointment->appointment_time) }}')"
                                                class="text-purple-400 hover:text-purple-300 text-xs bg-purple-600/20 hover:bg-purple-600/30 px-2 py-1 rounded transition-colors"
                                                title="Send appointment reminder to customer"
                                            >
                                                &#128276; Send Reminder
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-6 py-12 text-center">
                    <div class="text-gray-400 text-lg">No appointments found for this date</div>
                    <p class="text-gray-500 text-sm mt-2">Try selecting a different date or barber</p>
                </div>
            @endif
        </div>
    @endif

    <!-- Day Details Modal -->
    <div id="dayModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-gray-800 rounded-lg border border-gray-700 max-w-4xl w-full max-h-[90vh] overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-700 flex justify-between items-center">
                    <h3 id="modalTitle" class="text-lg font-semibold text-white">Day Details</h3>
                    <button onclick="closeDayModal()" class="text-gray-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div id="modalContent" class="p-6 overflow-y-auto max-h-[70vh]">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function setViewMode(mode) {
    document.getElementById('viewInput').value = mode;
    document.getElementById('filterForm').submit();
}

function openDayModal(date) {
    const modal = document.getElementById('dayModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    // Show loading state
    modalTitle.textContent = 'Loading...';
    modalContent.innerHTML = '<div class="text-center py-8"><div class="text-gray-400">Loading appointments...</div></div>';
    modal.classList.remove('hidden');
    
    // Get current barber filter
    const barberSelect = document.getElementById('barber');
    const selectedBarber = barberSelect ? barberSelect.value : 'all';
    
    // Fetch day details
    fetch(`/dashboard/day/${date}?barber=${selectedBarber}`)
        .then(response => response.json())
        .then(data => {
            modalTitle.textContent = `Appointments for ${data.date}`;
            
            if (data.appointments.length === 0) {
                modalContent.innerHTML = `
                    <div class="text-center py-8">
                        <div class="text-gray-400 text-lg">No appointments for this day</div>
                        <p class="text-gray-500 text-sm mt-2">This day is available for new bookings</p>
                    </div>
                `;
            } else {
                let appointmentsHtml = `
                    <div class="space-y-4">
                        ${data.appointments.map(appointment => `
                            <div class="bg-gray-700 rounded-lg p-4 border border-gray-600">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-4 mb-2">
                                            <div class="text-lg font-semibold text-white">${appointment.time}</div>
                                            <div class="text-sm px-2 py-1 rounded-full ${getStatusClasses(appointment)}">
                                                ${getStatusText(appointment)}
                                            </div>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <div class="text-sm font-medium text-white">${appointment.customer_name}</div>
                                                <div class="text-sm text-gray-400">${appointment.customer_email}</div>
                                                ${window.userIsAdmin ? `<div class="text-sm text-gray-300 mt-1">Barber: ${appointment.barber_name}</div>` : ''}
                                            </div>
                                            
                                            <div>
                                                <div class="text-sm text-gray-300">Services:</div>
                                                ${appointment.services ? appointment.services.map(service => 
                                                    `<div class="text-sm text-white">${service.name} - $${parseFloat(service.price).toFixed(2)}</div>`
                                                ).join('') : '<div class="text-sm text-gray-400">No services listed</div>'}
                                                
                                                <div class="text-sm font-medium text-white mt-2">
                                                    Total: $${parseFloat(appointment.total_amount).toFixed(2)}
                                                </div>
                                                ${appointment.tip_amount > 0 ? 
                                                    `<div class="text-sm text-green-400">Tip: $${parseFloat(appointment.tip_amount).toFixed(2)}</div>` : ''
                                                }
                                                ${appointment.is_no_show && appointment.no_show_charge_amount ? 
                                                    `<div class="text-sm text-red-400">No-show charge: $${parseFloat(appointment.no_show_charge_amount).toFixed(2)}</div>` : ''
                                                }
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex space-x-2 ml-4">
                                        ${appointment.can_mark_completed ? `
                                            <form method="POST" action="/appointments/${appointment.id}/mark-completed" class="inline">
                                                <input type="hidden" name="_token" value="${document.querySelector('meta[name="csrf-token"]').getAttribute('content')}">
                                                <button type="submit" class="text-green-400 hover:text-green-300 text-xs bg-green-600/20 hover:bg-green-600/30 px-2 py-1 rounded transition-colors" ${appointment.has_remaining_balance ? `onclick="return confirm('Mark as completed and charge remaining balance of $' + parseFloat(appointment.remaining_amount).toFixed(2) + '?')"` : ''}>
                                                    ✓ Complete${appointment.has_remaining_balance ? ' & Charge' : ''}
                                                </button>
                                            </form>
                                        ` : ''}
                                        
                                        ${appointment.can_mark_no_show ? `
                                            <form method="POST" action="/appointments/${appointment.id}/mark-no-show" class="inline">
                                                <input type="hidden" name="_token" value="${document.querySelector('meta[name="csrf-token"]').getAttribute('content')}">
                                                <button type="submit" class="text-red-400 hover:text-red-300 text-xs bg-red-600/20 hover:bg-red-600/30 px-2 py-1 rounded transition-colors" onclick="return confirm('Mark as no-show and charge full service amount ($' + parseFloat(appointment.total_amount).toFixed(2) + ')?')">
                                                    ✗ No-Show & Charge
                                                </button>
                                            </form>
                                        ` : ''}
                                        
                                        ${(appointment.status === 'completed' && appointment.has_remaining_balance) ? `
                                            <form method="POST" action="/appointments/${appointment.id}/charge-remaining" class="inline">
                                                <input type="hidden" name="_token" value="${document.querySelector('meta[name="csrf-token"]').getAttribute('content')}">
                                                <button type="submit" class="text-blue-400 hover:text-blue-300 text-xs bg-blue-600/20 hover:bg-blue-600/30 px-2 py-1 rounded transition-colors" onclick="return confirm('Charge remaining balance of $' + parseFloat(appointment.remaining_amount).toFixed(2) + '?')">
                                                    $ Charge Balance
                                                </button>
                                            </form>
                                        ` : ''}
                                        
                                        ${appointment.status !== 'cancelled' ? `
                                            <button onclick="sendReminder(${appointment.id}, '${appointment.customer_name}', '${appointment.barber_name}', '${data.date}', '${appointment.time}')" class="text-purple-400 hover:text-purple-300 text-xs bg-purple-600/20 hover:bg-purple-600/30 px-2 py-1 rounded transition-colors" title="Send appointment reminder to customer">
                                                📱 Send Reminder
                                            </button>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;
                modalContent.innerHTML = appointmentsHtml;
            }
        })
        .catch(error => {
            console.error('Error fetching day details:', error);
            modalContent.innerHTML = `
                <div class="text-center py-8">
                    <div class="text-red-400 text-lg">Error loading appointments</div>
                    <p class="text-gray-500 text-sm mt-2">Please try again later</p>
                </div>
            `;
        });
}

function closeDayModal() {
    document.getElementById('dayModal').classList.add('hidden');
}

function getStatusClasses(appointment) {
    if (appointment.is_no_show) {
        return 'bg-red-600 text-white';
    } else if (appointment.status === 'completed') {
        return 'bg-green-600 text-white';
    } else if (appointment.status === 'cancelled') {
        return 'bg-gray-600 text-white';
    } else {
        return 'bg-blue-600 text-white';
    }
}

function getStatusText(appointment) {
    if (appointment.is_no_show) {
        return 'No-Show';
    } else if (appointment.status === 'completed') {
        return 'Completed';
    } else if (appointment.status === 'cancelled') {
        return 'Cancelled';
    } else {
        return 'Scheduled';
    }
}

// Set user admin status for JavaScript
window.userIsAdmin = {{ $user && $user->isAdmin() ? 'true' : 'false' }};

// Dashboard debugging
console.log('Dashboard loaded at:', new Date());
console.log('User is admin:', window.userIsAdmin);
console.log('Current user:', '{{ $user ? $user->name : "Not authenticated" }}');

// Monitor DOM changes
const observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
        if (mutation.type === 'childList') {
            mutation.removedNodes.forEach(function(node) {
                if (node.nodeType === 1) { // Element node
                    console.log('Element removed from DOM:', node);
                    if (node.classList && (node.classList.contains('bg-red-600') || node.classList.contains('bg-green-600'))) {
                        console.log('Red/Green element removed:', node.className);
                    }
                }
            });
        }
        if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
            console.log('Style changed on element:', mutation.target, 'New style:', mutation.target.style.cssText);
        }
    });
});

// Start observing
observer.observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['style']
});

// View mode toggle function
function setViewMode(mode) {
    console.log('Setting view mode to:', mode);
    document.getElementById('viewInput').value = mode;
    document.getElementById('filterForm').submit();
}

// Global error handler to prevent page refresh on JavaScript errors
window.addEventListener('error', function(e) {
    console.error('JavaScript error:', e.error);
    e.preventDefault();
    return false;
});

// Log when page is fully loaded
window.addEventListener('load', function() {
    console.log('Page fully loaded');
    console.log('Statistics cards:', document.querySelectorAll('.bg-gray-800').length);
    console.log('Red elements:', document.querySelectorAll('.bg-red-600').length);
    console.log('Green elements:', document.querySelectorAll('.bg-green-600').length);
    console.log('Logout button:', document.querySelector('button[type="submit"]'));
    
    // Monitor elements every 2 seconds
    setInterval(function() {
        const logoutButton = document.querySelector('form[action*="logout"] button');
        const redElements = document.querySelectorAll('.bg-red-600');
        const greenElements = document.querySelectorAll('.bg-green-600');
        const statsCards = document.querySelectorAll('.bg-gray-800');
        
        console.log('Element check:', {
            time: new Date().toLocaleTimeString(),
            logoutButton: logoutButton ? 'present' : 'MISSING',
            redElements: redElements.length,
            greenElements: greenElements.length,
            statsCards: statsCards.length
        });
        
        if (!logoutButton) {
            console.error('LOGOUT BUTTON MISSING!');
            console.log('All forms:', document.querySelectorAll('form'));
            console.log('All buttons:', document.querySelectorAll('button'));
        }
        
        // Check for elements with opacity 0
        const hiddenElements = document.querySelectorAll('[style*="opacity: 0"]');
        if (hiddenElements.length > 0) {
            console.log('Hidden elements found:', hiddenElements);
        }
    }, 2000);
});

// Close modal when clicking outside
document.getElementById('dayModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDayModal();
    }
});

// Send appointment reminder
function sendReminder(appointmentId, customerName, barberName, appointmentDate, appointmentTime) {
    try {
        console.log('sendReminder called:', { appointmentId, customerName, barberName, appointmentDate, appointmentTime });
        
        if (!confirm(`Send appointment reminder to ${customerName}?\n\nAppointment: ${barberName} on ${appointmentDate} at ${appointmentTime}`)) {
            console.log('User cancelled reminder');
            return;
        }
        
        // Show loading state
        const button = event.target;
        console.log('Button element:', button);
        const originalText = button.innerHTML;
        console.log('Original button text:', originalText);
        button.innerHTML = '⏳ Sending...';
        button.disabled = true;
    
    console.log('Sending fetch request to:', `/appointments/${appointmentId}/send-reminder`);
    
    fetch(`/appointments/${appointmentId}/send-reminder`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
    .then(response => {
        console.log('Response received:', response);
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        if (data.success) {
            // Show success message
            showNotification('success', data.message);
            button.innerHTML = '✓ Sent';
            button.classList.remove('text-purple-400', 'hover:text-purple-300', 'bg-purple-600/20', 'hover:bg-purple-600/30');
            button.classList.add('text-green-400', 'bg-green-600/20');
            console.log('Button updated to success state');
            
            // Reset button after 3 seconds
            setTimeout(() => {
                console.log('Resetting button to original state');
                button.innerHTML = originalText;
                button.disabled = false;
                button.classList.remove('text-green-400', 'bg-green-600/20');
                button.classList.add('text-purple-400', 'hover:text-purple-300', 'bg-purple-600/20', 'hover:bg-purple-600/30');
            }, 3000);
        } else {
            console.log('Request failed:', data.error);
            showNotification('error', data.error || 'Failed to send reminder');
            button.innerHTML = originalText;
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error sending reminder:', error);
        showNotification('error', 'Failed to send reminder');
        button.innerHTML = originalText;
        button.disabled = false;
    });
    } catch (error) {
        console.error('Error in sendReminder function:', error);
        showNotification('error', 'Failed to send reminder');
    }
}

// Show notification message
function showNotification(type, message) {
    try {
        console.log('Showing notification:', type, message);
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 z-50 px-4 py-3 rounded-lg text-white transition-opacity duration-300 notification-popup ${
            type === 'success' ? 'bg-green-600' : 'bg-red-600'
        }`;
        notification.textContent = message;
        notification.setAttribute('data-notification', 'true');
        
        console.log('Created notification element:', notification);
        document.body.appendChild(notification);
        console.log('Notification added to DOM');
        
        // Fade out and remove after 5 seconds
        setTimeout(() => {
            console.log('Starting notification fade out');
            notification.style.opacity = '0';
            setTimeout(() => {
                if (notification.parentNode) {
                    console.log('Removing notification from DOM');
                    document.body.removeChild(notification);
                }
            }, 300);
        }, 5000);
    } catch (error) {
        console.error('Error showing notification:', error);
    }
}
</script>
@endsection