<?php

return [
    'driver' => env('BILLING_DRIVER', 'fake'),
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'prices' => [
            'starter_monthly' => env('STRIPE_PRICE_STARTER_MONTHLY'), 'starter_yearly' => env('STRIPE_PRICE_STARTER_YEARLY'),
            'pro_monthly' => env('STRIPE_PRICE_PRO_MONTHLY'), 'pro_yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
            'business_monthly' => env('STRIPE_PRICE_BUSINESS_MONTHLY'), 'business_yearly' => env('STRIPE_PRICE_BUSINESS_YEARLY'),
        ],
    ],
];
