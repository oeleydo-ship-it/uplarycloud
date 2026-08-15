<?php

return [
    // "system" performs real A/AAAA lookups. "fake" only simulates reserved demo hostnames (*.example.com);
    // public hostnames still use SystemDnsResolver so DNS_DRIVER=fake cannot false-verify production domains.
    'dns_driver' => env('DNS_DRIVER', env('INFRASTRUCTURE_DRIVER', 'fake') === 'ssh' ? 'system' : 'fake'),
    'proxy_image' => env('TRAEFIK_IMAGE', 'traefik:v3.6'),
    // Must match the proxy that server provisioning installs, otherwise a second
    // Traefik fights it for ports 80/443 and every domain configuration fails.
    'proxy_name' => env('TRAEFIK_CONTAINER', 'uplary-traefik'),
    'proxy_network' => env('TRAEFIK_NETWORK', 'uplary-proxy'),
    'proxy_dynamic_path' => env('TRAEFIK_DYNAMIC_PATH', '/opt/uplary/traefik/dynamic'),
    'proxy_certificates_volume' => env('TRAEFIK_CERT_VOLUME', 'uplary-traefik-certs'),
    'maintenance_container' => env('TRAEFIK_MAINTENANCE_CONTAINER', 'uplary-maintenance'),
    'maintenance_image' => env('TRAEFIK_MAINTENANCE_IMAGE', 'nginx:alpine'),
    'acme_email' => env('ACME_EMAIL', 'admin@example.com'),
    'renew_before_days' => (int) env('SSL_RENEW_BEFORE_DAYS', 30),
];
