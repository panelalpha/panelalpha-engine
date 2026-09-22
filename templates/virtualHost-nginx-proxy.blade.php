server {
@forelse ($ips_v4 as $ip)
    listen {{ $ip }}:80;
@empty
    listen 80;
@endforelse
@foreach ($ips_v6 as $ip)
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
            proxy_set_header X-Forwarded-Host $host;
            proxy_set_header X-Forwarded-Port $server_port;
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_read_timeout 3600s;
            proxy_send_timeout 3600s;
            set $userhost {{ $user }};
            proxy_pass http://$userhost:{{ $app_port }};
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
@forelse ($ips_v4 as $ip)
        listen {{ $ip }}:443 ssl;
@empty
        listen 443 ssl;
@endforelse
@foreach ($ips_v6 as $ip)
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
                proxy_set_header X-Forwarded-Host $host;
                proxy_set_header X-Forwarded-Port $server_port;
                proxy_set_header Upgrade $http_upgrade;
                proxy_set_header Connection "upgrade";
                proxy_read_timeout 3600s;
                proxy_send_timeout 3600s;
                proxy_ssl_server_name on;
                proxy_ssl_name $host;
                set $userhost {{ $user }};
                @if($app_ssl_port == 443)
                proxy_pass https://$userhost:{{ $app_ssl_port }};
                @else
                proxy_pass http://$userhost:{{ $app_ssl_port }};
                @endif
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