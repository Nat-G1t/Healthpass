<x-layout.sidebar title="College Admin Dashboard">

    @if (session('error'))
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    <x-hp.card>
        <p class="text-sm text-hp-slate">College Admin dashboard — placeholder.</p>
    </x-hp.card>

</x-layout.sidebar>
