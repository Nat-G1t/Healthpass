<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            {{-- Password changes moved to the OTP-confirmed Change Password
                 page (sidebar → Change Password); the direct form was removed
                 because it bypassed the email confirmation step. --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('Update Password') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        For your security, password changes are confirmed with a code sent to your email.
                    </p>
                    <a href="{{ route('password.change') }}"
                       class="mt-4 inline-block rounded-lg bg-hp-orange px-4 py-2 text-sm font-semibold text-white hover:bg-hp-orange/90">
                        Change Password
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
