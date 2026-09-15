<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Enums\JobStatus;
use App\Models\Application;
use App\Models\Interview;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Return aggregate statistics for the HR/admin dashboard.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $applicationsByStatus = Application::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $applicationsByStatus = collect(ApplicationStatus::cases())
            ->mapWithKeys(fn (ApplicationStatus $status) => [
                $status->value => (int) ($applicationsByStatus[$status->value] ?? 0),
            ]);

        return response()->json([
            'total_open_jobs' => JobPosting::where('status', JobStatus::Open)->count(),
            'applications_this_week' => Application::where('applied_at', '>=', now()->startOfWeek())->count(),
            'applications_by_status' => $applicationsByStatus,
            'upcoming_interviews' => Interview::where('status', InterviewStatus::Scheduled)
                ->whereBetween('scheduled_at', [now(), now()->addDays(7)])
                ->count(),
        ]);
    }
}
