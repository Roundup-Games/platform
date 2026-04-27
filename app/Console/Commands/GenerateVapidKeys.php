<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'pwa:generate-vapid-keys';

    protected $description = 'Generate VAPID key pairs for web push notifications';

    public function handle(): int
    {
        $existingPublicKey = config('services.vapid.public_key');
        $existingPrivateKey = config('services.vapid.private_key');

        if ($existingPublicKey && $existingPrivateKey && ! $this->confirm('VAPID keys already exist. Regenerate? This will invalidate existing push subscriptions.')) {
            $this->info('Using existing VAPID keys.');
            $this->displayKeys($existingPublicKey, $existingPrivateKey);

            return self::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();

        $this->displayKeys($keys['publicKey'], $keys['privateKey']);
        $this->newLine();
        $this->info('Add these to your .env file:');
        $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");

        return self::SUCCESS;
    }

    private function displayKeys(string $publicKey, string $privateKey): void
    {
        $this->newLine();
        $this->info('VAPID Keys Generated');
        $this->newLine();
        $this->line("<fg=green>Public Key:</>  {$publicKey}");
        $this->line("<fg=green>Private Key:</> {$privateKey}");
    }
}
