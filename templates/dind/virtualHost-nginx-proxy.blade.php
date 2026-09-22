{{-- DinD / container-app vhost: routing driven by ProxyRule rows for this domain. --}}
{{-- Legacy WordPress/PHP-FPM keeps templates/virtualHost-nginx-proxy.blade.php. --}}
server {
@forelse (($proxy_http['ips_v4'] ?? $ips_v4) as $ip)
    listen {{ $ip }}:80;
@empty
    listen 80;
@endforelse
@foreach (($proxy_http['ips_v6'] ?? $ips_v6) as $ip)
    listen [{{ $ip }}]:80;
@endforeach
    server_name  {{ $domain }}@if (!empty($aliases)) {{ implode(' ', $aliases) }}@endif;
    access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/{{ $domain }}/access.log combined;
    access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/{{ $domain }}/bytes.log bytes;
    error_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/{{ $domain }}/error.log error;
@if (!empty($http_acme_challenges_enabled))
    location ^~ /.well-known/acme-challenge/ {
        alias {{ $http_acme_challenges_dir }}/;
        default_type text/plain;
    }
@endif
    location / {
        @if(!empty($suspended))
            error_page 503 /account-suspended.html;
            location = /account-suspended.html {
                root  /opt/panelalpha/shared-hosting/webserver-config/document-root;
            }
            return 503;
        @elseif (!empty($redirect_url))
            return 301 "{!! $redirect_url !!}";
        @elseif (!empty($force_https_redirect))
            return 301 https://$host$request_uri;
        @elseif (!empty($proxy_http))
@if (!empty($site_password_enabled))
            {!! $site_password_auth_request !!}
@endif
            resolver 127.0.0.54 valid=30s;
            proxy_http_version 1.1;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $scheme;
            proxy_set_header X-Forwarded-Host $host;
            proxy_set_header X-Forwarded-Port $server_port;
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_read_timeout 3600s;
            proxy_send_timeout 3600s;
            set $proxyupstream {{ $proxy_http['host'] }};
            proxy_pass {{ $proxy_http['protocol'] ?? 'http' }}://$proxyupstream:{{ $proxy_http['port'] }};
        @else
@if (!empty($site_password_enabled))
            {!! $site_password_auth_request !!}
@endif
            root /home/{{ $user }}{{ $relative_document_root }};
            index index.html index.htm;
            try_files $uri $uri/ =404;
        @endif
    }
@if (!empty($site_password_enabled))
{!! $site_password_locations !!}
@endif
    location /{{ $user }}-error-pages/ {
        alias /opt/panelalpha/shared-hosting/webserver-config/error-pages/;
        internal;
    }
    error_page 403 /{{ $user }}-error-pages/403.html;
    error_page 404 /{{ $user }}-error-pages/404.html;
    error_page 500 /{{ $user }}-error-pages/500.html;
    error_page 502 /{{ $user }}-error-pages/502.html;
    error_page 503 /{{ $user }}-error-pages/503.html;
    location /phpmyadmin {
        proxy_set_header X-Real-IP $remote_addr;
        set $pmapass 0;
        if ($arg_pmassotoken) {
            set $pmapass 1;
        }
        if ($cookie_PMASignonSession) {
            set $pmapass 1;
        }
        if ($pmapass) {
            rewrite ^([^.]*[^/])$ $1/ permanent;
            rewrite ^/phpmyadmin(.*) /$1 break;
            proxy_pass http://phpmyadmin-users.shared-hosting.palocal;
        }
    }
    location /panelalpha-sso {
        resolver 127.0.0.54 valid=30s;
        set $enginehost core.shared-hosting.palocal;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        set $ssopass 0;
        if ($arg_token) {
            set $ssopass 1;
        }
        if ($ssopass) {
            proxy_pass http://$enginehost/api/users/{{ $user }}/app/sso-token$is_args$args;
        }
    }
}
@if (!empty($ssl_enabled))
    server {
@forelse (($proxy_https['ips_v4'] ?? $ips_v4) as $ip)
        listen {{ $ip }}:443 ssl;
@empty
        listen 443 ssl;
@endforelse
@foreach (($proxy_https['ips_v6'] ?? $ips_v6) as $ip)
        listen [{{ $ip }}]:443 ssl;
@endforeach
        http2 on;
        server_name  {{ $domain }}@if (!empty($aliases)) {{ implode(' ', $aliases) }}@endif;
        ssl_certificate {{ $ssl_cert_pem_file }};
        ssl_certificate_key {{ $ssl_cert_key_file }};
        proxy_hide_header Strict-Transport-Security;
        access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/{{ $domain }}/access.log combined;
        access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/{{ $domain }}/bytes.log bytes;
        error_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/{{ $domain }}/error.log error;
        location / {
            @if(!empty($suspended))
                error_page 503 /account-suspended.html;
                location = /account-suspended.html {
                    root  /opt/panelalpha/shared-hosting/webserver-config/document-root;
                }
                return 503;
            @elseif (!empty($redirect_url))
                return 301 "{!! $redirect_url !!}";
            @elseif (!empty($proxy_https))
@if (!empty($site_password_enabled))
                {!! $site_password_auth_request !!}
@endif
                resolver 127.0.0.54 valid=30s;
                proxy_http_version 1.1;
                proxy_set_header Host $host;
                proxy_set_header X-Real-IP $remote_addr;
                proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
                proxy_set_header X-Forwarded-Proto $scheme;
                proxy_set_header X-Forwarded-Host $host;
                proxy_set_header X-Forwarded-Port $server_port;
                proxy_set_header Upgrade $http_upgrade;
                proxy_set_header Connection "upgrade";
                proxy_read_timeout 3600s;
                proxy_send_timeout 3600s;
                proxy_ssl_server_name on;
                proxy_ssl_name $host;
                set $proxyupstream {{ $proxy_https['host'] }};
                @if(($proxy_https['protocol'] ?? 'http') === 'https')
                proxy_pass https://$proxyupstream:{{ $proxy_https['port'] }};
                @else
                proxy_pass http://$proxyupstream:{{ $proxy_https['port'] }};
                @endif
            @else
@if (!empty($site_password_enabled))
                {!! $site_password_auth_request !!}
@endif
                root /home/{{ $user }}{{ $relative_document_root }};
                index index.html index.htm;
                try_files $uri $uri/ =404;
            @endif
        }
@if (!empty($site_password_enabled))
{!! $site_password_locations !!}
@endif
        location /{{ $user }}-error-pages/ {
            alias /opt/panelalpha/shared-hosting/webserver-config/error-pages/;
            internal;
        }
        error_page 403 /{{ $user }}-error-pages/403.html;
        error_page 404 /{{ $user }}-error-pages/404.html;
        error_page 500 /{{ $user }}-error-pages/500.html;
        error_page 502 /{{ $user }}-error-pages/502.html;
        error_page 503 /{{ $user }}-error-pages/503.html;
        location /phpmyadmin {
            proxy_set_header X-Real-IP $remote_addr;
            set $pmapass 0;
            if ($arg_pmassotoken) {
                set $pmapass 1;
            }
            if ($cookie_PMASignonSession) {
                set $pmapass 1;
            }
            if ($pmapass) {
                rewrite ^([^.]*[^/])$ $1/ permanent;
                rewrite ^/phpmyadmin(.*) /$1 break;
                proxy_pass http://phpmyadmin-users.shared-hosting.palocal;
            }
        }
        location /panelalpha-sso {
            resolver 127.0.0.54 valid=30s;
            set $enginehost core.shared-hosting.palocal;
            proxy_set_header   Host              $host;
            proxy_set_header   X-Real-IP         $remote_addr;
            proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
            proxy_set_header   X-Forwarded-Proto $scheme;
            set $ssopass 0;
            if ($arg_token) {
                set $ssopass 1;
            }
            if ($ssopass) {
                proxy_pass http://$enginehost/api/users/{{ $user }}/app/sso-token$is_args$args;
            }
        }
    }
@endif
@foreach ($proxy_extra ?? [] as $extra)
server {
@forelse (($extra['ips_v4'] ?? $ips_v4) as $ip)
    listen {{ $ip }}:{{ $extra['listen_port'] }};
@empty
    listen {{ $extra['listen_port'] }};
@endforelse
@foreach (($extra['ips_v6'] ?? $ips_v6) as $ip)
    listen [{{ $ip }}]:{{ $extra['listen_port'] }};
@endforeach
    server_name  {{ $domain }}@if (!empty($aliases)) {{ implode(' ', $aliases) }}@endif;
    access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/{{ $domain }}/access.log combined;
    error_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/{{ $domain }}/error.log error;
    location / {
        @if(!empty($suspended))
            return 503;
        @else
@if (!empty($site_password_enabled))
            {!! $site_password_auth_request !!}
@endif
            resolver 127.0.0.54 valid=30s;
            proxy_http_version 1.1;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $scheme;
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_read_timeout 3600s;
            proxy_send_timeout 3600s;
            set $proxyupstream {{ $extra['host'] }};
            proxy_pass {{ $extra['protocol'] ?? 'http' }}://$proxyupstream:{{ $extra['port'] }};
        @endif
    }
@if (!empty($site_password_enabled))
{!! $site_password_locations !!}
@endif
}
@endforeach
