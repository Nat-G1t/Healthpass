{{-- Dev-only screen jumper (local env only). Lives outside the scaled panel so
     it is never letterboxed. Lets us reach placeholder screens during the week
     before their real entry paths exist. --}}
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
