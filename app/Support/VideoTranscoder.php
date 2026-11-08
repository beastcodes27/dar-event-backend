<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VideoTranscoder
{
    /** @return list<array{width: int, height: int, bandwidth: int}> */
    public function transcode(string $sourcePath, string $outputDirectory): array
    {
        $disk = Storage::disk('local');
        $source = $disk->path($sourcePath);
        $probe = Process::timeout(30)->run([
            config('video.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe',
            '-show_streams', '-show_format', '-of', 'json', $source,
        ])->throw();
        $metadata = json_decode($probe->output(), true, flags: JSON_THROW_ON_ERROR);
        $video = collect($metadata['streams'] ?? [])->firstWhere('codec_type', 'video');
        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        $rotation = (int) (collect($video['side_data_list'] ?? [])->firstWhere('side_data_type', 'Display Matrix')['rotation'] ?? $video['tags']['rotate'] ?? 0);
        if (abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }
        $duration = (float) ($metadata['format']['duration'] ?? 0);
        if ($width < 2 || $height < 2 || $width * $height > 3840 * 2160 || ! is_finite($duration) || $duration <= 0 || $duration > config('video.max_duration')) {
            throw new RuntimeException('Use a video up to 5 minutes and 4K resolution.');
        }
        $levels = array_values(array_filter([240, 480, 720], fn (int $size) => $size <= min($width, $height)));
        if ($levels === []) {
            $levels = [(int) floor(min($width, $height) / 2) * 2];
        }
        $bitrates = [280000, 800000, 1600000];
        $variants = [];
        $master = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-INDEPENDENT-SEGMENTS\n";
        $disk->makeDirectory($outputDirectory);
        foreach ($levels as $index => $size) {
            $longSide = (int) ceil($size * 16 / 9 / 2) * 2;
            [$capWidth, $capHeight] = $width >= $height ? [$longSide, $size] : [$size, $longSide];
            $scale = min(1, $capWidth / $width, $capHeight / $height);
            $outWidth = max(2, (int) floor($width * $scale / 2) * 2);
            $outHeight = max(2, (int) floor($height * $scale / 2) * 2);
            $bitrate = $bitrates[$index];
            $directory = $outputDirectory.'/v'.$index;
            $disk->makeDirectory($directory);
            Process::timeout(900)->run([
                config('video.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
                '-protocol_whitelist', 'file,pipe', '-i', $source, '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', "scale={$outWidth}:{$outHeight},fps=30", '-c:v', 'libx264', '-preset', 'veryfast',
                '-profile:v', 'main', '-pix_fmt', 'yuv420p', '-crf', '27', '-maxrate', (string) $bitrate,
                '-bufsize', (string) ($bitrate * 2), '-g', '60', '-keyint_min', '60', '-sc_threshold', '0',
                '-c:a', 'aac', '-b:a', '64k', '-ac', '1', '-ar', '44100', '-threads', '2',
                '-f', 'hls', '-hls_time', '2', '-hls_playlist_type', 'vod', '-hls_flags', 'independent_segments',
                '-hls_segment_filename', $disk->path($directory.'/segment%05d.ts'), $disk->path($directory.'/index.m3u8'),
            ])->throw();
            $bandwidth = (int) ceil(($bitrate + 64000) * 1.2);
            $variants[] = ['width' => $outWidth, 'height' => $outHeight, 'bandwidth' => $bandwidth];
            $master .= "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},RESOLUTION={$outWidth}x{$outHeight}\nv{$index}/index.m3u8\n";
        }
        $disk->put($outputDirectory.'/master.m3u8', $master);

        return $variants;
    }
}
