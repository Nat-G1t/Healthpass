<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        // Latest clearance: student → clinic_visits → clearance_records
        $latestClearance = $user->clinicVisits()
            ->whereHas('clearanceRecord')
            ->with('clearanceRecord')
            ->latest()
            ->first()
            ?->clearanceRecord;

        // Nearest upcoming scheduled appointment
        $nextAppointment = $user->appointments()
            ->where('status', 'scheduled')
            ->where('scheduled_date', '>=', today())
            ->oldest('scheduled_date')
            ->first();

        // Count of visits that have an encoded clearance
        $pastClearancesCount = $user->clinicVisits()
            ->whereHas('clearanceRecord')
            ->count();

        // Recent activity feed — up to 8 events, newest first
        $recentAppointments = $user->appointments()->latest()->take(8)->get();
        $recentVisits = $user->clinicVisits()
            ->with('clearanceRecord')
            ->latest()
            ->take(8)
            ->get();

        $recentActivity = collect()
            ->merge(
                $recentAppointments->map(fn ($a) => [
                    'icon' => $a->status === 'cancelled' ? 'x' : 'calendar',
                    'label' => $a->status === 'cancelled' ? 'Appointment cancelled' : 'Appointment booked',
                    'detail' => $a->reference_no.' · '.ucfirst($a->service_type).' Clearance',
                    'at' => $a->created_at,
                ])
            )
            ->merge(
                $recentVisits->flatMap(function ($v) {
                    $events = [];

                    if ($v->checked_in_at) {
                        $events[] = [
                            'icon' => 'checkin',
                            'label' => 'Checked in at clinic',
                            'detail' => $v->reference_no,
                            'at' => $v->checked_in_at,
                        ];
                    }

                    if ($v->clearanceRecord) {
                        $events[] = [
                            'icon' => 'result',
                            'label' => 'Clearance result: '.$v->clearanceRecord->result,
                            'detail' => $v->reference_no,
                            'at' => $v->clearanceRecord->encoded_at ?? $v->clearanceRecord->created_at,
                        ];
                    }

                    return $events;
                })
            )
            ->filter(fn ($item) => $item['at'] !== null)
            ->sortByDesc('at')
            ->take(8)
            ->values();

        return view('student.dashboard', compact(
            'latestClearance',
            'nextAppointment',
            'pastClearancesCount',
            'recentActivity',
        ));
    }
}
