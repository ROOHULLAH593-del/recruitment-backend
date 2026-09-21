<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CandidateDocumentController;
use App\Http\Controllers\CandidateProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HrInvitationController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\JobPostingController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:6,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // Public invitation flow — no auth, the token itself is the credential
    // for these two actions. Throttled the same as register/login since
    // they're the other unauthenticated write-capable endpoints in the app.
    Route::get('/invitations/{invitation:token}', [HrInvitationController::class, 'show']);
    Route::post('/invitations/{invitation:token}/apply', [HrInvitationController::class, 'apply']);
});

// Public job board: viewable without authentication (open postings only for
// guests/candidates); optionally authenticated so hr/admin see every status.
Route::get('/jobs', [JobPostingController::class, 'index']);
Route::get('/jobs/{job}', [JobPostingController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/password', [UserController::class, 'updatePassword']);

    Route::post('/jobs', [JobPostingController::class, 'store']);
    Route::put('/jobs/{job}', [JobPostingController::class, 'update']);
    Route::delete('/jobs/{job}', [JobPostingController::class, 'destroy']);
    Route::post('/jobs/{job}/apply', [ApplicationController::class, 'store']);

    Route::get('/profile', [CandidateProfileController::class, 'show']);
    Route::put('/profile', [CandidateProfileController::class, 'update']);

    // Throttled separately — each request calls a paid external API, so an
    // already-compromised candidate session shouldn't be able to hammer it
    // for free the way it could an ordinary CRUD route.
    Route::middleware('throttle:10,1')->post('/profile/resume-upload', [CandidateProfileController::class, 'uploadResume']);
    Route::post('/profile/documents', [CandidateProfileController::class, 'uploadDocument']);
    Route::get('/candidate-documents/{profile}/{documentType}', [CandidateDocumentController::class, 'show']);

    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::get('/applications/{application}', [ApplicationController::class, 'show']);
    Route::patch('/applications/{application}/status', [ApplicationController::class, 'updateStatus']);
    Route::post('/applications/{application}/interview', [InterviewController::class, 'store']);

    Route::get('/interviews', [InterviewController::class, 'index']);
    Route::get('/interviews/{interview}', [InterviewController::class, 'show']);
    Route::patch('/interviews/{interview}', [InterviewController::class, 'update']);

    Route::get('/dashboard/stats', [DashboardController::class, 'index']);

    // Authenticated, admin-only, but still throttled — nothing else in this
    // auth:sanctum group has a rate limit (the app enables no default `api`
    // limiter), so without this an already-compromised admin session could
    // hammer invitation creation/accept/reject with no cap at all.
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/admin/invitations', [HrInvitationController::class, 'store']);
        Route::get('/admin/invitations', [HrInvitationController::class, 'index']);
        Route::post('/admin/invitations/{invitation}/accept', [HrInvitationController::class, 'accept']);
        Route::post('/admin/invitations/{invitation}/reject', [HrInvitationController::class, 'reject']);
    });
});
