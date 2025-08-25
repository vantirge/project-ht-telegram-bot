<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class NotificationApiController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required','string','max:1000'],
        ]);

        $notification = Notification::create([
            'description' => $data['description'],
        ]);

        Log::info('External notification created', ['id' => $notification->id]);

        return response()->json([
            'status' => 'ok',
            'id' => $notification->id,
        ]);
    }
}

