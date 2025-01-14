<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        $factory = (new Factory)->withServiceAccount(storage_path('app/firebase/firebase-service-key.json'));
        $this->messaging = $factory->createMessaging();
    }

    /**
     * Send notification to a specific device
     *
     * @param string $token FCM device token
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data
     * @return mixed[]
     */
    public function sendNotification(string $token, string $title, string $body, array $data = []): array
    {
        $notification = Notification::create($title, $body);
        
        $message = CloudMessage::withTarget('token', $token)
            ->withNotification($notification);
        
        if (!empty($data)) {
            $message = $message->withData($data);
        }
        
        return $this->messaging->send($message);
    }

    /**
     * Send notification to multiple devices
     *
     * @param array $tokens Array of FCM device tokens
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data
     * @return array
     */
    public function sendMulticastNotification(array $tokens, string $title, string $body, array $data = []): array
    {
        $notification = Notification::create($title, $body);
        
        $message = CloudMessage::new()
            ->withNotification($notification);
            
        if (!empty($data)) {
            $message = $message->withData($data);
        }
        
        return $this->messaging->sendMulticast($message, $tokens);
    }
} 