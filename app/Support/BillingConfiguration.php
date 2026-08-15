<?php

namespace App\Support;

class BillingConfiguration
{
    public function driver(): string
    {
        $fromSettings = $this->setting('billing_driver');

        return $fromSettings ?: (string) config('billing.driver', 'fake');
    }

    public function stripeSecret(): ?string
    {
        return $this->setting('stripe_secret') ?: config('billing.stripe.secret');
    }

    public function stripeWebhookSecret(): ?string
    {
        return $this->setting('stripe_webhook_secret') ?: config('billing.stripe.webhook_secret');
    }

    public function requiresPaymentGateway(): bool
    {
        return $this->driver() === 'stripe' && filled($this->stripeSecret());
    }

    public function allowsInstantActivation(): bool
    {
        if ($this->requiresPaymentGateway()) {
            return false;
        }

        return (bool) config('billing.allow_instant_activation', false);
    }

    private function setting(string $key): ?string
    {
        try {
            $value = app(PlatformSettings::class)->get('payments', $key);

            return filled($value) ? (string) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
