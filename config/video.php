<?php

return [
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    'max_duration' => 300,
    'max_upload_kb' => 102400,
];
