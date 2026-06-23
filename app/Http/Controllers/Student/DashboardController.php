<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
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
            ->where('status', '!=', 'cancelled')
            ->where('scheduled_date', '>=', today())
            ->oldest('scheduled_date')
            ->first();

        // Count of visits that have an encoded clearance
        $pastClearancesCount = $user->clinicVisits()
            ->whereHas('clearanceRecord')
            ->count();

        // Clinic hours formatted for display in the Next Appointment card
        $clinicHoursLabel = sprintf(
            '%s – %s',
            Carbon::createFromTimeString(config('healthpass.clinic_hours.open'))->format('g:i A'),
            Carbon::createFromTimeString(config('healthpass.clinic_hours.close'))->format('g:i A'),
        );

        // Fetch more than 8 per source so the merged sort picks the true 8 newest overall
        $recentAppointments = $user->appointments()->latest()->take(16)->get();
        $recentVisits = $user->clinicVisits()
            ->with('clearanceRecord')
            ->latest()
            ->take(16)
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
            ->merge([
                [
                    'icon' => 'registered',
                    'label' => 'Account registered',
                    'detail' => $user->email,
                    'at' => $user->created_at,
                ],
            ])
            ->filter(fn ($item) => $item['at'] !== null)
            ->sortByDesc('at')
            ->take(8)
            ->values();

        return view('student.dashboard', compact(
            'latestClearance',
            'nextAppointment',
            'pastClearancesCount',
            'clinicHoursLabel',
            'recentActivity',
        ));
    }
}
