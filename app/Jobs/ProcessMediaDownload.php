<?php

namespace App\Jobs;

use App\Models\Download;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use RuntimeException;
use Throwable;

class ProcessMediaDownload implements ShouldQueue
{
    use Queueable;

    public int $timeout = 660;
    public int $tries = 1;

    public function __construct(
        public int $downloadId
    ) {}

    private function ytDlpCommand(): array
    {
        return array_merge(
            [config('services.yt_dlp.command', 'py')],
            config('services.yt_dlp.arguments', ['-m', 'yt_dlp'])
        );
    }

    private function windowsEnvironment(): array
    {
        $env = [];

        foreach (['SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP'] as $key) {
            $value = getenv($key);

            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }

        $env['SystemRoot'] ??= 'C:\\Windows';
        $env['WINDIR'] ??= $env['SystemRoot'];
        $env['PATH'] ??= 'C:\\Windows\\System32;C:\\Windows';

        $env['TEMP'] ??= sys_get_temp_dir();
        $env['TMP'] ??= sys_get_temp_dir();

        $ffmpegDir = config('services.ffmpeg_location');

        if (is_string($ffmpegDir) && $ffmpegDir !== '') {
            $env['PATH'] = $ffmpegDir . PATH_SEPARATOR . $env['PATH'];
        }

        return $env;
    }

    private function makeProcess(
        array $arguments,
        int $timeout
    ): Process {
        $process = new Process(
            array_merge($this->ytDlpCommand(), $arguments),
            base_path(),
            $this->windowsEnvironment()
        );

        $process->setTimeout($timeout);

        return $process;
    }

    /**
     * Find out whether a media file really contains an audio stream.
     */
    private function hasAudio(string $filePath): bool
    {
        $ffmpegDir = config('services.ffmpeg_location');

        $ffprobe = rtrim((string) $ffmpegDir, '/\\')
            . DIRECTORY_SEPARATOR . 'ffprobe.exe';

        if (!is_file($ffprobe)) {
            throw new RuntimeException(
                'ffprobe.exe was not found. Check FFMPEG_LOCATION in .env.'
            );
        }

        $process = new Process([
            $ffprobe,
            '-v',
            'error',
            '-select_streams',
            'a:0',
            '-show_entries',
            'stream=codec_type',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $filePath,
        ], base_path(), $this->windowsEnvironment());

        $process->setTimeout(30);
        $process->run();

        $output = trim($process->getOutput());
        $error = trim($process->getErrorOutput());

        if (!$process->isSuccessful()) {
            logger()->warning('ffprobe failed to inspect downloaded file', [
                'file' => $filePath,
                'exit_code' => $process->getExitCode(),
                'output' => $output,
                'error' => $error,
            ]);

            return false;
        }

        return preg_match('/(?:^|\R)audio(?:\R|$)/i', $output) === 1;
    }

    public function handle(): void
    {
        $download = Download::findOrFail($this->downloadId);

        $download->update([
            'status' => 'processing',
            'progress' => 0,
            'error_message' => null,
        ]);

        $disk = Storage::disk('local');
        $disk->makeDirectory('downloads');

        $basePath = $disk->path('downloads/' . $download->id);

        try {
            /*
             * Ensure FFmpeg and FFprobe are configured.
             */
            $ffmpegDir = config('services.ffmpeg_location');

            if (
                !is_string($ffmpegDir) ||
                !is_file(rtrim($ffmpegDir, '/\\') . DIRECTORY_SEPARATOR . 'ffmpeg.exe')
            ) {
                throw new RuntimeException(
                    'ffmpeg.exe not found. Check FFMPEG_LOCATION in .env.'
                );
            }

            /*
             * Remove files left by previous attempts.
             */
            foreach (glob($basePath . '.*') ?: [] as $oldFile) {
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }

            /*
             * Retrieve media metadata.
             */
            $metadata = $this->makeProcess([
                '--dump-single-json',
                '--skip-download',
                '--no-playlist',
                '--no-warnings',
                '--socket-timeout',
                '20',
                '--',
                $download->url,
            ], 60);

            $metadata->run();

            if (!$metadata->isSuccessful()) {
                $error = trim($metadata->getErrorOutput());

                throw new RuntimeException(
                    'Metadata failed: ' .
                        mb_substr($error ?: $metadata->getOutput(), 0, 1500)
                );
            }

            $info = json_decode($metadata->getOutput(), true);

            if (!is_array($info)) {
                throw new RuntimeException('Invalid media metadata JSON.');
            }

            $download->update([
                'title' => $info['title'] ?? 'Media download',
                'thumbnail' => $info['thumbnail'] ?? null,
            ]);

            /*
             * Download video + audio.
             *
             * bv+ba selects separate video-only and audio-only streams.
             * /b is the fallback for a combined format.
             */
            $process = $this->makeProcess([
                '--no-playlist',
                '--no-warnings',
                '--newline',
                '--progress',
                '--progress-template',
                'download:%(progress._percent_str)s',
                '--max-filesize',
                '500M',
                '--ffmpeg-location',
                $ffmpegDir,
                '-f',
                'bv+ba/b',
                '--merge-output-format',
                'mp4',
                '-o',
                $basePath . '.%(ext)s',
                '--socket-timeout',
                '20',
                '--retries',
                '2',
                '--',
                $download->url,
            ], 600);

            $lineBuffer = '';

            $process->run(function (
                string $type,
                string $buffer
            ) use ($download, &$lineBuffer): void {
                if ($type !== Process::OUT) {
                    return;
                }

                $lineBuffer .= $buffer;

                $lines = preg_split('/\r\n|\r|\n/', $lineBuffer);
                $lineBuffer = array_pop($lines) ?? '';

                foreach ($lines as $line) {
                    if (preg_match(
                        '/download:\s*([\d.]+)\s*%/',
                        $line,
                        $matches
                    )) {
                        $percent = (int) round((float) $matches[1]);

                        $download->update([
                            'progress' => min(99, max(0, $percent)),
                        ]);
                    }
                }
            });

            if (!$process->isSuccessful()) {
                $error = trim($process->getErrorOutput());

                logger()->error('yt-dlp process failed', [
                    'download_id' => $download->id,
                    'exit_code' => $process->getExitCode(),
                    'stderr' => $error,
                    'stdout' => mb_substr($process->getOutput(), 0, 3000),
                ]);

                throw new RuntimeException(
                    'Download failed: ' .
                        mb_substr($error ?: $process->getOutput(), 0, 1500)
                );
            }

            /*
             * Find possible output files.
             * Correct glob pattern: basePath + .*
             */
            $files = glob($basePath . '.*') ?: [];

            $excludedExtensions = [
                '.part',
                '.ytdl',
                '.json',
                '.description',
                '.jpg',
                '.jpeg',
                '.png',
                '.webp',
                '.vtt',
                '.srt',
            ];

            $files = array_values(array_filter(
                $files,
                static function (string $file) use ($excludedExtensions): bool {
                    if (!is_file($file) || (filesize($file) ?: 0) <= 0) {
                        return false;
                    }

                    $lower = strtolower($file);

                    foreach ($excludedExtensions as $extension) {
                        if (str_ends_with($lower, $extension)) {
                            return false;
                        }
                    }

                    return true;
                }
            ));

            /*
             * Prefer the merged MP4, then check other candidates.
             * Never mark a video-only file as completed.
             */
            usort(
                $files,
                static function (string $a, string $b) use ($basePath): int {
                    $aIsMp4 = strtolower($a) === strtolower($basePath . '.mp4');
                    $bIsMp4 = strtolower($b) === strtolower($basePath . '.mp4');

                    if ($aIsMp4 !== $bIsMp4) {
                        return $aIsMp4 ? -1 : 1;
                    }

                    return (filesize($b) ?: 0) <=> (filesize($a) ?: 0);
                }
            );

            $file = null;

            foreach ($files as $candidate) {
                if ($this->hasAudio($candidate)) {
                    $file = $candidate;
                    break;
                }
            }

            if ($file === null) {
                logger()->error('No output file with audio stream found', [
                    'download_id' => $download->id,
                    'candidate_files' => array_map(
                        static fn(string $path): array => [
                            'name' => basename($path),
                            'size' => filesize($path) ?: 0,
                        ],
                        $files
                    ),
                    'yt_dlp_stdout' => mb_substr(
                        $process->getOutput(),
                        0,
                        3000
                    ),
                    'yt_dlp_stderr' => mb_substr(
                        $process->getErrorOutput(),
                        0,
                        3000
                    ),
                ]);

                throw new RuntimeException(
                    'No downloaded output file contains an audio stream. ' .
                        'Check the latest laravel.log diagnostic entry.'
                );
            }

            $relativePath = 'downloads/' . basename($file);

            $download->update([
                'file_path' => $relativePath,
                'status' => 'completed',
                'progress' => 100,
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            logger()->error('Media download job failed', [
                'download_id' => $this->downloadId,
                'error' => $e->getMessage(),
            ]);

            $download->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }
}
