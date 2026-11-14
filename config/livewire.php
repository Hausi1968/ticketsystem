<?php

// Nur die Abweichungen von den Livewire-Standardwerten.
return [
    'temporary_file_upload' => [
        'disk' => 'local',
        // Anhänge bis 100 MB (siehe Konzept 6b). Auf dem Webhosting müssen upload_max_filesize
        // und post_max_size mindestens so groß sein (public/.user.ini).
        'rules' => ['required', 'file', 'max:102400'],
        'directory' => 'livewire-tmp',
        'middleware' => 'throttle:60,1',
        'preview_mimes' => ['png', 'gif', 'jpg', 'jpeg', 'webp'],
        'max_upload_time' => 15,
        'cleanup' => true,
    ],
];
