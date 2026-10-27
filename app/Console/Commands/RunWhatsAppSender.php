<?php

namespace App\Console\Commands;

use App\Shop\WhatsApp;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Runs the WhatsApp sender (whatsapp-sender/server.js) with the website's settings: the port from
 * WHATSAPP_SENDER_URL, the shared WHATSAPP_SENDER_TOKEN, where to report back (route "whatsapp.status") and where
 * to keep its data (WHATSAPP_SENDER_DATA_DIR, optional), so they are set in one place. `php artisan dev` starts it once those are in .env and `npm install` was run in
 * whatsapp-sender/; on the server it runs as a daemon (Forge: Daemons → php artisan whatsapp:sender). More settings
 * (pace, daily limit) can go in whatsapp-sender/.env; see its README.
 */
class RunWhatsAppSender extends Command
{
    protected $signature = 'whatsapp:sender
        {--dry-run : Pretend to send: nothing goes to WhatsApp}
        {--node=node : The Node.js program, if it is not on the PATH}';

    protected $description = 'Run the WhatsApp sender for order updates to buyers';

    public function handle(): int
    {
        if (! WhatsApp::configured()) {
            $this->error('Set WHATSAPP_SENDER_URL and WHATSAPP_SENDER_TOKEN in .env first.');

            return self::FAILURE;
        }
        $directory = base_path('whatsapp-sender');
        if (! is_dir($directory.'/node_modules')) {
            $this->error('Run "npm install" in whatsapp-sender/ first.');

            return self::FAILURE;
        }

        $url = parse_url((string) config('services.whatsapp_sender.url'));
        $process = new Process([$this->option('node'), 'server.js'], $directory, array_filter([
            'TOKEN' => (string) config('services.whatsapp_sender.token'),
            'HOST' => '127.0.0.1',
            'PORT' => (string) ($url['port'] ?? 3100),
            'CALLBACK_URL' => route('whatsapp.status'),
            'DATA_DIR' => config('services.whatsapp_sender.data_dir'),
            'DRY_RUN' => $this->option('dry-run') ? 'true' : null,
        ]), null, null);

        // Stopping this command (Ctrl+C, the daemon being restarted) stops the sender and its browser too.
        if (extension_loaded('pcntl')) {
            $this->trap([SIGINT, SIGTERM], fn () => $process->isRunning() ? $process->signal(SIGTERM) : null);
        }

        $process->run(fn (string $type, string $output) => $this->output->write($output));

        return $process->getExitCode() ?? self::FAILURE;
    }
}
