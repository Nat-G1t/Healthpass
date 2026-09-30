<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `healthpass:demo-students` (D-98) — tops a college up to N ACTIVE students
 * for the defense demo. It only adds: existing students (active or not) are
 * never touched, and a college at the target is left alone.
 */
class DemoStudentsCommandTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cit = College::create(['code' => 'CIT', 'name' => 'College of Industrial Technology']);
    }

    private function activeCount(College $college): int
    {
        return $college->studentProfiles()->whereHas('user', fn ($q) => $q->where('status', 'active'))->count();
    }

    public function test_it_tops_each_college_up_to_the_target_without_touching_anyone(): void
    {
        // CCS already has 4 active students and one graduate.
        $existing = StudentProfile::factory()->count(4)->forCollege($this->ccs)->create();
        $graduate = User::factory()->create(['role' => 'student', 'status' => 'inactive']);
        StudentProfile::factory()->forCollege($this->ccs)->create(['user_id' => $graduate->id]);

        $this->artisan('healthpass:demo-students', ['colleges' => ['ccs', 'CIT'], '--target' => 10])
            ->assertSuccessful();

        $this->assertSame(10, $this->activeCount($this->ccs));
        $this->assertSame(10, $this->activeCount($this->cit));

        // Nobody was changed: the 4 are still there and the graduate is still inactive.
        $this->assertSame(4, StudentProfile::whereIn('id', $existing->pluck('id'))->count());
        $this->assertSame('inactive', $graduate->fresh()->status);

        // The added accounts use Resend test addresses and demo numbers.
        $added = User::where('email', 'like', 'delivered+ccs-demo-%@resend.dev')->get();
        $this->assertCount(6, $added);
        $this->assertTrue($added->every(fn (User $user) => $user->role === 'student' && $user->status === 'active'));
        $this->assertSame(6, StudentProfile::where('student_number', 'like', '20268%')->where('college_id', $this->ccs->id)->count());
    }

    public function test_running_it_again_adds_nobody(): void
    {
        $this->artisan('healthpass:demo-students', ['colleges' => ['CCS'], '--target' => 5])->assertSuccessful();
        $this->artisan('healthpass:demo-students', ['colleges' => ['CCS'], '--target' => 5])->assertSuccessful();

        $this->assertSame(5, $this->activeCount($this->ccs));
        $this->assertSame(5, User::where('role', 'student')->count());
    }

    public function test_an_unknown_college_code_fails(): void
    {
        $this->artisan('healthpass:demo-students', ['colleges' => ['XYZ']])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
