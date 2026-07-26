<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-hp-white border border-gray-300 dark:border-hp-slate/25 rounded-md font-semibold text-xs text-gray-700 dark:text-hp-slate/80 uppercase tracking-widest shadow-sm hover:bg-gray-50 hover:dark:bg-hp-white hover:dark:bg-hp-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:dark:ring-hp-orange focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
