<?php

namespace App\Console\Commands;

use App\Appearance\Background;
use App\Shop\OnePay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PubNub\Callbacks\SubscribeCallback;
use PubNub\Endpoints\PubSub\Subscribe;
use PubNub\Enums\PNStatusCategory;
use PubNub\Exceptions\PubNubConnectionException;
use PubNub\PNConfiguration;
use PubNub\PubNub;
use Throwable;

/**
 * Receives BCEL One (OnePay) payments and marks the orders paid (App\Shop\OnePay::paid()).
 *
 * - `onepay:listen` keeps listening, so orders turn Paid within seconds. `php artisan dev` starts it;
 *   on the server it runs as a daemon (Forge: Daemons → php artisan onepay:listen).
 * - `onepay:listen --once` fetches what arrived since the last payment handled, then stops. The scheduler
 *   runs it every minute (routes/console.php), so payments still get through within a minute when the
 *   listener isn't running.
 * Both start from the last payment handled (or the last 30 minutes), as far back as BCEL's channel keeps
 * messages; a payment handled twice changes nothing.
 */
class ListenForOnePayPayments extends Command
{
    protected $signature = 'onepay:listen {--once : Fetch what came in since the last check, then stop}';

    protected $description = 'Receive BCEL One (OnePay) payments and mark the orders paid';

    const LAST = 'onepay:last-timetoken';

    public function handle(): int
    {
        if (! OnePay::configured()) {
            $this->warn('BCEL OnePay is not set up (ONEPAY_* in .env), so there is nothing to listen to.');

            return self::SUCCESS;
        }

        $config = (new PNConfiguration)
            ->setSubscribeKey((string) config('onepay.subscribe_key'))
            // We only listen; BCEL keeps the real publish key. The PHP library still insists on one before
            // subscribing, and only uses it to sign requests when a secret key is set (never here).
            ->setPublishKey('subscribe-only')
            ->setUserId('daim-av-hlub-'.Str::slug((string) gethostname()))
            ->setSecure(true);

        return $this->option('once') ? $this->once($config) : $this->listen($config);
    }

    /** One request for what arrived since the last check. */
    private function once(PNConfiguration $config): int
    {
        // With nothing new, the channel waits for a message; don't wait long.
        $pubnub = new PubNub($config->setSubscribeTimeout(10));

        try {
            $envelope = (new Subscribe($pubnub))->channels([OnePay::channel()])->setTimetoken(self::from())->sync();
        } catch (PubNubConnectionException $e) {
            if ($e->getStatus()?->getCategory() === PNStatusCategory::PNTimeoutCategory) {
                $this->say('no new payments');

                return self::SUCCESS;
            }
            Log::warning('OnePay check: '.$e->getMessage());
            $this->say('problem: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            Log::warning('OnePay check: '.$e->getMessage());
            $this->say('problem: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($envelope->getMessages() as $message) {
            $this->received($message->getPayload(), (string) $message->getPublishMetaData()->getPublishTimetoken());
        }
        if ($timetoken = $envelope->getMetadata()?->getTimetoken()) {
            Cache::forever(self::LAST, (string) $timetoken);
        }
        $this->say(count($envelope->getMessages()).' new message(s)');

        return self::SUCCESS;
    }

    /** Keeps listening (and reconnects after a problem). */
    private function listen(PNConfiguration $config): int
    {
        $pubnub = new PubNub($config);
        $pubnub->addListener($this->listener());

        $channel = OnePay::channel();
        $this->info('Listening for BCEL One payments'.(OnePay::testing() ? ' (BCEL test merchant)' : '').': '.$channel);

        // One quick request first: a waiting subscription only reports back when something arrives, so a
        // wrong key or no internet would otherwise go unnoticed for minutes.
        try {
            (new Subscribe($pubnub))->channels([$channel])->setTimetoken(0)->sync();
            $this->say('connected to BCEL\'s payment channel');
        } catch (Throwable $e) {
            Log::warning('OnePay listener cannot reach BCEL\'s payment channel: '.$e->getMessage());
            $this->error('Cannot reach BCEL\'s payment channel: '.$e->getMessage());
        }

        while (true) {
            try {
                $pubnub->subscribe()->channels($channel)->withTimetoken(self::from())->execute();
            } catch (Throwable $e) {
                Log::warning('OnePay listener: '.$e->getMessage());
                $this->error($e->getMessage());
            }
            sleep(5);
        }
    }

    /** Where to start: just after the last message handled, or 30 minutes ago (in 1/10,000,000 s). */
    private static function from(): string
    {
        return (string) (Cache::get(self::LAST) ?: now()->subMinutes(30)->getTimestampMs() * 10000);
    }

    /** One message from BCEL's channel. */
    public function received(mixed $payload, string $timetoken): void
    {
        $data = is_string($payload) ? json_decode($payload, true) : json_decode(json_encode($payload), true);
        $result = OnePay::paid(is_array($data) ? $data : []);
        Cache::forever(self::LAST, $timetoken);

        // Only our own orders are logged: the test merchant's channel carries other people's tests too.
        if (! in_array($result, ['unknown', 'ignored'], true)) {
            Log::info("OnePay: {$result} for order ".($data['iid'] ?? $data['uuid'] ?? '?'));
            $this->say("{$result}: ".($data['iid'] ?? '?'));
        } else {
            $this->say($result.' (not one of our orders)');
        }
    }

    /** A line of output with the time in Laos. */
    public function say(string $text): void
    {
        $this->line(now()->setTimezone(Background::TIMEZONE)->format('H:i:s').' '.$text);
    }

    private function listener(): SubscribeCallback
    {
        return new class($this) extends SubscribeCallback
        {
            public function __construct(private ListenForOnePayPayments $command) {}

            public function status($pubnub, $status)
            {
                $category = $status->getCategory();
                if (in_array($category, [PNStatusCategory::PNConnectedCategory, PNStatusCategory::PNReconnectedCategory], true)) {
                    $this->command->say('listening');
                } elseif ($category !== PNStatusCategory::PNAcknowledgmentCategory) {
                    // Not every problem is flagged as an error by the library, so anything else is reported.
                    $problem = $status->getException()?->getMessage() ?: 'status '.$category;
                    Log::warning("OnePay listener: {$problem}");
                    $this->command->say("problem ({$problem}), trying again");
                }
            }

            public function message($pubnub, $message)
            {
                $this->command->received($message->getMessage(), (string) $message->getTimetoken());
            }

            public function presence($pubnub, $presence) {}
        };
    }
}
