{{-- On-screen QWERTY keyboard, shared by the email-login screen (FR-KSK-02),
     the staff-exit prompt (FR-KSK-16) and the questionnaire's YES-details panel
     (D-56). Each include passes the `target` it types into:
       'login'  (default) → state.login (email + password); Enter submits the
                student login or the staff exit, whichever is open.
       'detail' → the detail open in the details panel; Enter closes the panel.
     Because every keyboard names its own target, a key never has to guess where
     it belongs. The LAYOUT lives in this small nested x-data; all behaviour is
     on the kiosk component (keyPress / pressKey / backspaceDown / kbMods /
     kbSending / kbEnterLabel). --}}
@php($target = $target ?? 'login')
{{-- hp-anim-sheet-up (§7): the keyboard slides up whenever its host (the
     email-login screen, the staff-exit prompt or the details panel) brings it
     on screen. --}}
<div
    class="hp-anim-sheet-up flex w-full max-w-2xl flex-col gap-1.5 select-none"
    x-data="{
        rows: [
            ['1','2','3','4','5','6','7','8','9','0'],
            ['q','w','e','r','t','y','u','i','o','p'],
            ['a','s','d','f','g','h','j','k','l','@'],
            ['z','x','c','v','b','n','m','.','_','-'],
        ],
    }"
>
    <template x-for="(row, i) in rows" :key="i">
        <div class="flex justify-center gap-1.5">
            <template x-for="key in row" :key="key">
                <button
                    type="button"
                    @pointerdown="pressKey($event.currentTarget)"
                    @animationend="$event.currentTarget.classList.remove('k-key-press')"
                    @click="keyPress(key, @js($target))"
                    class="h-12 flex-1 rounded-lg bg-hp-white text-xl font-medium text-hp-slate shadow-sm"
                    x-text="keyLabel(key, @js($target))"
                ></button>
            </template>
        </div>
    </template>

    {{-- Modifier + action row: Caps · Shift · Space · Delete (peach) · Enter (orange).
         Caps/Shift highlight orange while engaged; press feedback elsewhere
         is `active:` only, so nothing stays highlighted after a tap. --}}
    <div class="flex justify-center gap-1.5">
        <button
            type="button"
            @click="keyPress('caps', @js($target))"
            class="h-12 flex-[2] rounded-lg text-base font-semibold shadow-sm transition active:scale-95"
            :class="kbMods(@js($target)).caps ? 'bg-hp-orange text-hp-white' : 'bg-hp-white text-hp-slate active:bg-hp-peach/60'"
        >⇪ Caps</button>
        <button
            type="button"
            @click="keyPress('shift', @js($target))"
            class="h-12 flex-[2] rounded-lg text-base font-semibold shadow-sm transition active:scale-95"
            :class="kbMods(@js($target)).shift ? 'bg-hp-orange text-hp-white' : 'bg-hp-white text-hp-slate active:bg-hp-peach/60'"
        >⇧ Shift</button>
        <button
            type="button"
            @click="keyPress('space', @js($target))"
            class="h-12 flex-[4] rounded-lg bg-hp-white text-base font-medium text-hp-slate/70 shadow-sm transition active:scale-95 active:bg-hp-peach/60"
        >Space</button>
        {{-- Long-press accelerates the delete and clears the field after
             2 s held (backspaceDown/Up). Pointer capture guarantees the
             release fires on this button even if the finger drifts off. --}}
        <button
            type="button"
            @pointerdown="$event.currentTarget.setPointerCapture($event.pointerId); backspaceDown(@js($target))"
            @pointerup="backspaceUp()"
            @pointercancel="backspaceUp()"
            class="h-12 flex-[2] rounded-lg bg-hp-peach text-base font-semibold text-hp-slate shadow-sm transition active:scale-95 active:brightness-90"
        >⌫ Delete</button>
        <button
            type="button"
            @click="keyPress('enter', @js($target))"
            :disabled="kbSending(@js($target))"
            class="h-12 flex-[2] rounded-lg bg-hp-orange text-base font-semibold text-hp-white shadow-sm transition active:scale-95 active:brightness-90 disabled:opacity-60"
            x-text="kbEnterLabel(@js($target))"
        ></button>
    </div>
</div>
