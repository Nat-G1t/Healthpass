<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use Tests\TestCase;

/**
 * D-89: the kiosk page carries the student's top-left Cancel and its
 * "Start over?" popup, and the hidden staff exit has moved off that corner to
 * the top-centre. Which screens show Cancel, and what Start over does, is the
 * state machine's job — see tests/js/kiosk-state-machine.test.js.
 */
class KioskCancelTest extends TestCase
{
    public function test_the_kiosk_has_the_cancel_button_and_its_confirmation(): void
    {
        $this->get(route('kiosk.index'))
            ->assertOk()
            ->assertSee('x-show="canCancel()"', false)
            ->assertSee('✕ Cancel')
            ->assertSee('Start over?')
            ->assertSee('Keep going');
    }

    public function test_the_staff_exit_hotspot_left_the_top_left_corner(): void
    {
        $this->get(route('kiosk.index'))
            ->assertOk()
            ->assertSee('@click="exitTap()"', false)
            ->assertSee('absolute left-1/2 top-0 z-30', false)
            ->assertDontSee('absolute left-0 top-0 z-30', false);
    }
}
