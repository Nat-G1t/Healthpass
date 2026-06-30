{{-- On-screen QWERTY keyboard, shared by the email-login screen (FR-KSK-02) and
     the staff-exit prompt (FR-KSK-16). Both type into `state.login` (email +
     password), so the keyboard behaviour is identical; only the Enter key knows
     which flow is open (handled in keyPress → submitLogin / submitExit). The
     LAYOUT lives in this small nested x-data; all behaviour is on the kiosk
     component (keyPress / pressKey / backspaceDown / kbSending / kbEnterLabel). --}}
<div
    class="flex w-full max-w-2xl flex-col gap-1 select-none"
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
        <div class="flex justify-center gap-1">
            <template x-for="key in row" :key="key">
                <button
                    type="button"
                    @pointerdown="pressKey($event.currentTarget)"
                    @animationend="$event.currentTarget.classList.remove('k-key-press')"
                    @click="keyPress(key)"
                    class="h-9 flex-1 rounded-lg bg-hp-white text-base font-medium text-hp-slate shadow-sm"
                    x-text="keyLabel(key)"
                ></button>
            </template>
        </div>
    </template>

    {{-- Modifier + action row: Caps · Shift · Space · Delete (peach) · Enter (orange).
         Caps/Shift highlight orange while engaged; press feedback elsewhere
         is `active:` only, so nothing stays highlighted after a tap. --}}
    <div class="flex justify-center gap-1">
        <button
            type="button"
            @click="keyPress('caps')"
            class="h-9 flex-[2] rounded-lg text-sm font-semibold shadow-sm transition active:scale-95"
            :class="state.login.caps ? 'bg-hp-orange text-hp-white' : 'bg-hp-white text-hp-slate active:bg-hp-peach/60'"
        >⇪ Caps</button>
        <button
            type="button"
            @click="keyPress('shift')"
            class="h-9 flex-[2] rounded-lg text-sm font-semibold shadow-sm transition active:scale-95"
            :class="state.login.shift ? 'bg-hp-orange text-hp-white' : 'bg-hp-white text-hp-slate active:bg-hp-peach/60'"
        >⇧ Shift</button>
        <button
            type="button"
            @click="keyPress('space')"
            class="h-9 flex-[4] rounded-lg bg-hp-white text-sm font-medium text-hp-slate/70 shadow-sm transition active:scale-95 active:bg-hp-peach/60"
        >Space</button>
        {{-- Long-press accelerates the delete and clears the field after
             2 s held (backspaceDown/Up). Pointer capture guarantees the
             release fires on this button even if the finger drifts off. --}}
        <button
            type="button"
            @pointerdown="$event.currentTarget.setPointerCapture($event.pointerId); backspaceDown()"
            @pointerup="backspaceUp()"
            @pointercancel="backspaceUp()"
            class="h-9 flex-[2] rounded-lg bg-hp-peach text-sm font-semibold text-hp-slate shadow-sm transition active:scale-95 active:brightness-90"
        >⌫ Delete</button>
        <button
            type="button"
            @click="keyPress('enter')"
            :disabled="kbSending()"
            class="h-9 flex-[2] rounded-lg bg-hp-orange text-sm font-semibold text-hp-white shadow-sm transition active:scale-95 active:brightness-90 disabled:opacity-60"
            x-text="kbEnterLabel()"
        ></button>
    </div>
</div>
