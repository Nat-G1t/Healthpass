{{-- Dev-only screen jumper (local env only). Fixed to the bottom edge, outside
     the panel's layout flow. Lets us jump straight to any screen while
     iterating (sized in px, so it ignores --k-zoom on purpose). --}}
<div class="kiosk-devbar">
    <span class="self-center pr-1 text-[10px] font-semibold uppercase tracking-wider text-white/50">dev</span>
    <button type="button" @click="go('welcome')">welcome</button>
    <button type="button" @click="goEmailLogin()">email</button>
    <button type="button" @click="go('identity')">identity</button>
    <button type="button" @click="go('consent')">consent</button>
    <button type="button" @click="go('vitals')">vitals</button>
    <button type="button" @click="go('questionnaire')">quest.</button>
    <button type="button" @click="go('review')">review</button>
    <button type="button" @click="go('complete')">complete</button>
    <button type="button" @click="reset()">reset</button>
</div>
