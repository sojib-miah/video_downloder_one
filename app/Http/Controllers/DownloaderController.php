<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMediaDownload;
use App\Models\Download;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\Process\Process;

class DownloaderController extends Controller
{
    private function ytDlpCommand(): array
    {
        return array_merge(
            [config('services.yt_dlp.command', 'py')],
            config('services.yt_dlp.arguments', ['-m', 'yt_dlp'])
        );
    }

    private function validateMediaUrl(string $url): string
    {
        $parts = parse_url($url);

        if (
            !$parts ||
            strtolower($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host']) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['port'])
        ) {
            throw ValidationException::withMessages([
                'url' => 'একটি valid HTTPS media URL দাও।',
            ]);
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        $domains = [
            'youtube.com',
            'youtu.be',
            'youtube-nocookie.com',
            'instagram.com',
            'facebook.com',
            'fb.watch',
            'twitter.com',
            'x.com',
        ];

        $allowed = collect($domains)->contains(
            fn($domain) =>
            $host === $domain ||
                Str::endsWith($host, '.' . $domain)
        );

        if (!$allowed) {
            throw ValidationException::withMessages([
                'url' => 'এই media domain সমর্থিত নয়।',
            ]);
        }

        return $url;
    }

    private function getPlatform(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (
            $host === 'youtu.be' ||
            $host === 'youtube.com' ||
            Str::endsWith($host, '.youtube.com') ||
            $host === 'youtube-nocookie.com' ||
            Str::endsWith($host, '.youtube-nocookie.com')
        ) {
            return 'youtube';
        }

        if (
            $host === 'instagram.com' ||
            Str::endsWith($host, '.instagram.com')
        ) {
            return 'instagram';
        }

        if (
            $host === 'facebook.com' ||
            Str::endsWith($host, '.facebook.com') ||
            $host === 'fb.watch' ||
            Str::endsWith($host, '.fb.watch')
        ) {
            return 'facebook';
        }

        return 'x';
    }

    public function index()
    {
        return Inertia::render('Home');
    }


    public function preview(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $url = $this->validateMediaUrl($data['url']);

        $command = array_merge(
            $this->ytDlpCommand(),
            [
                '--dump-single-json',
                '--skip-download',
                '--no-playlist',
                '--no-warnings',
                '--socket-timeout',
                '20',
                '--',
                $url,
            ]
        );

        // Windows environment variables for the child process.
        $windowsEnv = [
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
            'WINDIR' => getenv('WINDIR') ?: 'C:\\Windows',
            'PATH' => getenv('PATH') ?: 'C:\\Windows\\System32;C:\\Windows',
            'TEMP' => getenv('TEMP') ?: sys_get_temp_dir(),
            'TMP' => getenv('TMP') ?: sys_get_temp_dir(),
        ];

        $process = new Process(
            $command,
            base_path(),
            $windowsEnv
        );

        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            logger()->error('yt-dlp preview failed', [
                'exit_code' => $process->getExitCode(),
                'command' => $command[0] ?? null,
                'stderr' => mb_substr($process->getErrorOutput(), 0, 5000),
                'stdout' => mb_substr($process->getOutput(), 0, 2000),
            ]);

            return response()->json([
                'message' => 'Preview পাওয়া যায়নি। Laravel log পরীক্ষা করো।',
            ], 422);
        }

        $info = json_decode($process->getOutput(), true);

        if (!is_array($info)) {
            logger()->error('Invalid yt-dlp JSON', [
                'json_error' => json_last_error_msg(),
                'output' => mb_substr($process->getOutput(), 0, 2000),
            ]);

            return response()->json([
                'message' => 'Media metadata পড়া যায়নি।',
            ], 422);
        }

        return response()->json([
            'platform' => $this->getPlatform($url),
            'title' => $info['title'] ?? 'Untitled media',
            'thumbnail' => $info['thumbnail'] ?? null,
            'duration' => $info['duration'] ?? null,
            'is_video' => ($info['vcodec'] ?? 'none') !== 'none',
        ]);
    }


    public function start(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $url = $this->validateMediaUrl($data['url']);

        $download = Download::create([
            'url' => $url,
            'platform' => $this->getPlatform($url),
            'status' => 'queued',
            'progress' => 0,
        ]);

        ProcessMediaDownload::dispatch($download->id);

        return response()->json([
            'id' => $download->id,
            'status' => 'queued',
            'progress' => 0,
        ], 202);
    }

    public function status(Download $download)
    {
        return response()->json([
            'id' => $download->id,
            'title' => $download->title,
            'status' => $download->status,
            'progress' => $download->progress,
            'error' => $download->error_message,
            'download_url' => $download->status === 'completed'
                ? route('media.file', $download)
                : null,
        ]);
    }

    public function file(Download $download)
    {
        abort_unless(
            $download->status === 'completed' &&
                $download->file_path &&
                Storage::disk('local')->exists($download->file_path),
            404
        );

        return Storage::disk('local')->download(
            $download->file_path
        );
    }
}
