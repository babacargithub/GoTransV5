<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SmsMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Depart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MessengerController extends Controller
{
    /**
     * Get a batch of SMS messages for a specific device
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSmsBatch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string',
            'device_name' => 'required|string',
            'batch_size' => 'required|integer|min:1|max:100'
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
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function updateSmsStatus(Request $request)
    {
        return response()->noContent();
    }

    /**
     * Update device activity timestamp and ensure it exists
     *
     * @param string $deviceId
     * @param string $deviceName
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
     * @param Request $request
     * @return JsonResponse|\Illuminate\Http\Response
     */
    public function sendHeartbeat(Request $request)
    {
         $request->validate([
            'device_id' => 'required|string',
            'device_name' => 'required|string',
            'sms_sent_count' => 'required|integer'
        ]);


        // Find the device
        $device = Device::where('device_id', $request->device_id)->first();

        if (!$device) {
            // If device doesn't exist, create it
            $device = new Device();
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

        if (!$device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $message = $device->messages()->where('status', 'PENDING')->first();

        if (!$message) {
            return response()->json(['message' => 'No message to send'], 404);
        }

        return response()->json($message);

    }

    public function createMessages(Request $request)
    {
        $messages = $request->input("messages");
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
                $formattedMessages = array_map(function($message) {
                    // Ensure message has all required fields
                    return [
                        'to' => $message['to'] ?? null,
                        'text' => $message['text'] ?? null,
                        'status' => SmsMessage::STATUS_PENDING,
                      'created_at' => now(),
                        'updated_at' => now()
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
            'details' => 'nullable|array'
        ]);

        $device = Device::where('device_id', $request->device_id)->first();

        if (!$device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $message = $device->messages()->where('id', $request->message_id)->first();

        if (!$message) {
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

        if (!$device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $message = $device->messages()->where('id', $request->message_id)->first();

        if (!$message) {
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
        DB::raw('CONCAT(trajets.name, " ", departs.name) as name')
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
        ->map(function($booking) {
            return [
                'id' => $booking->id,
                'name' => $booking->customer->full_name,
                'phone_number' => $booking->customer->phone_number,
                "field1" => $booking->point_dep->name,
                "field2"=> $booking->formatted_schedule,
                "field3"=> $booking->point_dep->arret_bus,
                "field4"=> $booking->bus->name,
                "field5"=> $booking->seat != null ? $booking->seat->number : "N/C",
            ];
        });
        return response()->json($customers);
    }

    /**
     * Get a list of all files in the storage directory
     * 
     * @return JsonResponse
     */
    public function getBatchExcelFiles()
    {
        // Specify the directory where your files are stored
        $files = Storage::files('public/batch_excel_files');

        
        $filesList = [];
        
        foreach ($files as $file) {
            $fileName = basename($file);
            $fileSize = Storage::size($file);
            $mimeType = Storage::mimeType($file);
            
            $filesList[] = [
                'name' => $fileName,
                'size' => $fileSize,
                'type' => $mimeType,
                'download_url' => route('messenger.download-file', ['filename' => $fileName])
            ];
        }
        
        return response()->json($filesList);
    }
    
    /**
     * Download a specific file
     * 
     * @param string $filename
     * @return JsonResponse|BinaryFileResponse
     */
    public function downloadFile(string $filename)
    {
        $path = storage_path('app/public/batch_excel_files/' . $filename);
        
        if (!File::exists($path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'File not found'
            ], 404);
        }
        
        return response()->download($path);
    }
}