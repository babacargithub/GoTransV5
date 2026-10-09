<?php

namespace App\Http\Controllers;

use App\Enums\PermissionName;
use App\Models\Booking;
use App\Models\CallLog;
use App\Models\Depart;
use App\Models\Device;
use App\Models\HeureDepart;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MessengerController extends Controller
{
    public const SMS_GATEWAY_TOKEN_ABILITY = 'sms-gateway';

    public const SMS_GATEWAY_TOKEN_LIFETIME_IN_DAYS = 90;

    /**
     * Get a batch of SMS messages for a specific device
     *
     * @return JsonResponse
     */
    public function getSmsBatch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string',
            'device_name' => 'required|string',
            'batch_size' => 'required|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update device last seen timestamp
        $this->updateDeviceActivity($request->device_id, $request->device_name);

        // Fetch pending messages for the device
        $messages = [];

        // Mark messages as SENT
        foreach ($messages as $message) {
            $message->status = 'SENT';
            $message->sent_at = now();
            $message->save();
        }

        return response()->json($messages);
    }

    /**
     * Update the status of an SMS message
     *
     * @return Response
     */
    public function updateSmsStatus(Request $request)
    {
        return response()->noContent();
    }

    /**
     * Update device activity timestamp and ensure it exists
     *
     * @param  string  $deviceId
     * @param  string  $deviceName
     * @return void
     */
    private function updateDeviceActivity($deviceId, $deviceName)
    {
        // Update device last seen timestamp
        $device = Device::firstOrCreate(['device_id' => $deviceId], [
            'name' => $deviceName,
            'device_id' => $deviceId,
        ]);

        $device->last_heartbeat = now();
        $device->save();

    }

    public function registerDevice(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string',
            'device_name' => 'required|string',
            'phone_number' => 'required|string',
        ]);

        // Update device last seen timestamp
        $device = Device::firstOrNew(['device_id' => $request->device_id]);
        $device->name = $request->device_name;
        $device->last_heartbeat = now();
        $device->save();

        return response()->noContent();

    }

    /**
     * Process device heartbeat
     *
     * @return JsonResponse|Response
     */
    public function sendHeartbeat(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string',
            'device_name' => 'required|string',
            'sms_sent_count' => 'required|integer',
        ]);

        // Find the device
        $device = Device::where('device_id', $request->device_id)->first();

        if (! $device) {
            // If device doesn't exist, create it
            $device = new Device;
            $device->device_id = $request->device_id;
            $device->name = $request->device_name;
            $device->last_heartbeat = now();
            $device->save();
        }
        $device->last_heartbeat = now();
        $device->save();

        Log::info("Heartbeat from {$request->device_id}: {$request->sms_sent_count} messages sent");

        return response()->noContent();
    }

    public function nextMessageToSend(Request $request)
    {
        $device = Device::where('device_id', $request->device_id)->first();

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $message = $device->messages()->where('status', 'PENDING')->first();

        if (! $message) {
            return response()->json(['message' => 'No message to send'], 404);
        }

        return response()->json($message);

    }

    public function createMessages(Request $request)
    {
        PermissionName::SendMessages->authorizeForCurrentUser();

        $messages = $request->input('messages');
        $devicesCount = Device::count();

        if ($devicesCount === 0) {
            return response()->json(['error' => 'No devices available to assign messages'], 400);
        }

        // Divide messages by devices using equal chunks
        $messagesPerDevice = ceil(count($messages) / $devicesCount);
        $messagesChunks = array_chunk($messages, $messagesPerDevice > 0 ? $messagesPerDevice : 1);

        // Get all devices
        $devices = Device::all();

        // Loop through chunks and assign them to devices
        foreach ($messagesChunks as $index => $chunk) {
            // Only process if we have devices left
            if ($index < $devicesCount) {
                // Get the device at this index position
                $device = $devices[$index];

                // Map the messages to have the correct format for createMany
                $formattedMessages = array_map(function ($message) {
                    // Ensure message has all required fields
                    return [
                        'to' => $message['to'] ?? null,
                        'text' => $message['text'] ?? null,
                        'status' => SmsMessage::STATUS_PENDING,
                        'created_at' => now(),
                        'updated_at' => now(),
                        // Add any other required fields here
                    ];
                }, $chunk);

                // Create the messages for this device
                $device->messages()->createMany($formattedMessages);
            }
        }

        return response()->json(['message' => 'Messages created successfully']);
    }

    public function reportMessageSendingResult(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string',
            'message_id' => 'required|integer',
            'status' => 'required|string|in:SENT,FAILED',
            'details' => 'nullable|array',
        ]);

        $device = Device::where('device_id', $request->device_id)->first();

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $message = $device->messages()->where('id', $request->message_id)->first();

        if (! $message) {
            return response()->json(['message' => 'Message not found'], 404);
        }

        $message->status = $request->status;
        $message->sent_at = now();
        $message->save();

        return response()->noContent();
    }

    public function markMessageAsProcessing(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string',
            'message_id' => 'required|integer',
        ]);

        $device = Device::where('device_id', $request->device_id)->first();

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $message = $device->messages()->where('id', $request->message_id)->first();

        if (! $message) {
            return response()->json(['message' => 'Message not found'], 404);
        }

        $message->status = SmsMessage::STATUS_PROCESSING;
        $message->save();

        return response()->noContent();
    }

    public function getDepartsForBulkSms(Request $request)
    {
        $departs = Depart::query()
            ->select([
                'departs.id',
                DB::raw('CONCAT(trajets.name, " ", departs.name) as name'),
            ])
            ->join('trajets', 'trajets.id', '=', 'departs.trajet_id')
            ->withCount(['bookings' => function ($query) {
                $query->whereNotNull('ticket_id')
                    ->whereNull('bookings.deleted_at');
            }])
            ->where('departs.canceled', false)
            ->orderByDesc('departs.date')
            ->limit(30)
            ->get();

        return response()->json($departs);
    }

    public function getDepartCustomersForBulkSms(Request $request, Depart $depart)
    {
        $customers = $depart->bookings()->whereNotNull('ticket_id')
            ->get()
            ->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'name' => $booking->customer->full_name,
                    'phone_number' => $booking->customer->phone_number,
                    'field1' => $booking->point_dep->name,
                    'field2' => $booking->formatted_schedule,
                    'field3' => $booking->point_dep->arret_bus,
                    'field4' => $booking->bus->name,
                    'field5' => $booking->seat != null ? $booking->seat->number : 'N/C',
                ];
            });

        return response()->json($customers);
    }

    /**
     * Exchanges username/password for a long-lived token that only opens the SMS Gateway routes.
     * The user must be allowed to send messages.
     */
    public function issueSmsGatewayToken(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if (! Auth::validate($credentials)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        $user = User::where('username', $credentials['username'])->firstOrFail();

        if (! $user->can(PermissionName::SendMessages->value)) {
            return response()->json(['message' => 'Cet utilisateur n\'est pas autorisé à envoyer des messages.'], 403);
        }

        $expiresAt = now()->addDays(self::SMS_GATEWAY_TOKEN_LIFETIME_IN_DAYS);
        $token = $user->createToken('sms-gateway', [self::SMS_GATEWAY_TOKEN_ABILITY], $expiresAt);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Departs with their buses, for the SMS Gateway app to pick which contacts to import.
     * Upcoming departs (from now, soonest first) are unlimited unless a limit is given; past departs
     * (most recent first) default to 50.
     */
    public function departsForSms(Request $request): JsonResponse
    {
        PermissionName::SendMessages->authorizeForCurrentUser();

        $validated = $request->validate([
            'period' => 'required|in:past,upcoming',
            'limit' => 'nullable|integer|min:1',
        ]);

        $isPastPeriod = $validated['period'] === 'past';
        $limit = $validated['limit'] ?? ($isPastPeriod ? 50 : null);

        $departs = Depart::query()
            ->with('buses:id,name,depart_id')
            ->when(
                $isPastPeriod,
                fn ($query) => $query->where('date', '<', now())->reorder('date', 'desc'),
                fn ($query) => $query->notPassed()->reorder('date', 'asc'),
            )
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (Depart $depart): array => [
                'id' => $depart->id,
                'depart_name' => $depart->name,
                'buses' => $depart->buses
                    ->map(fn ($bus): array => ['id' => $bus->id, 'name' => $bus->name])
                    ->values(),
            ]);

        return response()->json($departs);
    }

    /**
     * Contacts of the bookings of the given buses or départs, for import into the SMS Gateway app.
     * The filter narrows to paid (ticket issued) or unpaid bookings; "all" (default) keeps everything.
     */
    public function contactsForSms(Request $request): JsonResponse
    {
        PermissionName::SendMessages->authorizeForCurrentUser();

        $validated = $request->validate([
            'entity' => 'required|in:bus,depart',
            'entity_ids' => 'required|array|min:1',
            'entity_ids.*' => 'integer',
            'filter' => 'nullable|in:paid,unpaid,all',
        ]);

        $entityColumn = $validated['entity'] === 'bus' ? 'bus_id' : 'depart_id';
        $paymentFilter = $validated['filter'] ?? 'all';

        $contacts = Booking::query()
            ->whereIn($entityColumn, $validated['entity_ids'])
            ->when($paymentFilter === 'paid', fn ($query) => $query->whereNotNull('ticket_id'))
            ->when($paymentFilter === 'unpaid', fn ($query) => $query->whereNull('ticket_id'))
            ->with(['customer', 'point_dep', 'destination', 'depart.heuresDeparts', 'bus.heuresDeparts', 'seat.seat'])
            ->orderBy('id')
            ->get()
            ->map(function (Booking $booking): array {
                $busStopSchedule = $this->resolveBusStopScheduleOfBooking($booking);

                return [
                    'booking_id' => $booking->id,
                    'name' => $booking->passenger_full_name,
                    'phone' => $booking->customer?->phone_number,
                    'point_dep' => $booking->point_dep?->name,
                    'destination' => $booking->destination?->name,
                    'agent_number' => $booking->bus?->agent_numbers,
                    'depart_name' => $booking->depart?->name,
                    'bus_name' => $booking->bus?->name,
                    'seat_number' => $booking->seat_number,
                    'formatted_schedule' => $busStopSchedule?->heureDepart->format('H:i'),
                    'arret_bus' => $busStopSchedule?->arretBus ?: $booking->point_dep?->arret_bus,
                ];
            });

        return response()->json($contacts);
    }

    /**
     * The rendez-vous stop of a booking: the bus's own stop for the booking's point de départ, then the
     * départ's, then the départ's earliest one. Same order as Booking::formatted_schedule, but null instead
     * of an exception when the départ has no schedule at all (legacy data).
     */
    private function resolveBusStopScheduleOfBooking(Booking $booking): ?HeureDepart
    {
        return $booking->bus?->heuresDeparts->firstWhere('point_dep_id', $booking->point_dep_id)
            ?? $booking->depart?->heuresDeparts->firstWhere('point_dep_id', $booking->point_dep_id)
            ?? $booking->depart?->heuresDeparts->sortBy('heureDepart')->first();
    }

    /**
     * Get a list of all files in the storage directory
     *
     * @return JsonResponse
     */
    public function getBatchExcelFiles()
    {
        // Specify the directory where your files are stored
        $files = Storage::disk('public')->files('batch_excel_files');
        //        $files = glob(public_path('storage/batch_excel_files/*'));

        $filesList = [];

        foreach ($files as $file) {
            $fileName = basename($file);
            //            $fileSize = Storage::size($file);
            $fileSize = 19920393;
            $mimeType = Storage::mimeType($file);

            $filesList[] = [
                'name' => $fileName,
                'size' => $fileSize,
                'type' => $mimeType,
                'download_url' => route('messenger.download-file', ['filename' => $fileName]),
            ];
        }

        return response()->json($filesList);
    }

    /**
     * Download a specific file
     *
     * @return JsonResponse|BinaryFileResponse
     */
    public function downloadFile(string $filename)
    {
        $path = storage_path('app/public/batch_excel_files/'.$filename);

        if (! File::exists($path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'File not found',
            ], 404);
        }

        return response()->download($path);
    }

    public function callLogs()
    {
        $call_logs = CallLog::limit(100)
            ->get()
            ->map(function (CallLog $callLog) {
                return [
                    'phone_number' => $callLog->caller_phone_number,
                    'contact_name' => $callLog->contact_name,
                    'created_at' => $callLog->created_at->format('d/m H:i'),
                    'call_type' => $callLog->call_type,
                    // TODO change later
                    'device_name' => Device::where('id', $callLog->device_id)?->first()->name,
                ];

            });

        return response()->json($call_logs);
    }
}
