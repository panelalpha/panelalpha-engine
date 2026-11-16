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
    access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx/{{ $domain }}/access.log combined;
    access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx/{{ $domain }}/bytes.log bytes;
    error_log /opt/panelalpha/shared-hosting/webserver-logs/nginx/{{ $domain }}/error.log error;

    root /home/{{ $user }}{{ $relative_document_root }};
@if (!empty($cookie_isolation_shared_parents))
    js_var $pa_cookie_shared_parents "{!! $cookie_isolation_shared_parents !!}";
@endif
@if (!empty($cookie_isolation_server))
    {!! $cookie_isolation_server !!}
@endif
@if (!empty($http_acme_challenges_enabled))
    location ^~ /.well-known/acme-challenge/ {
        alias {{ $http_acme_challenges_dir }}/;
        default_type text/plain;
    }
@endif
    location ^~ /phpmyadmin {
        proxy_set_header X-Real-IP $remote_addr;
@if (!empty($cookie_isolation_location))
        {!! $cookie_isolation_location !!}
@endif
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
    @if(!empty($suspended))
        location / {
            error_page 503 /account-suspended.html;
            location = /account-suspended.html {
                root  /opt/panelalpha/shared-hosting/webserver-config/document-root;
            }
            return 503;
        }
    @elseif (!empty($redirect_url))
        location / {
            return 301 "{!! $redirect_url !!}";
        }
    @elseif (!empty($force_https_redirect))
        location / {
            return 301 https://$host$request_uri;
        }
    @else
        location /{{ $user }}-error-pages/ {
            alias /opt/panelalpha/shared-hosting/webserver-config/error-pages/;
            internal;
        }
        error_page 403 /{{ $user }}-error-pages/403.html;
        error_page 404 /{{ $user }}-error-pages/404.html;
        error_page 500 /{{ $user }}-error-pages/500.html;
        error_page 502 /{{ $user }}-error-pages/502.html;
        error_page 503 /{{ $user }}-error-pages/503.html;
        location / {
            index  index.php index.html index.htm;
            try_files $uri $uri/ /index.php?$args;
@if (!empty($cookie_isolation_shared_parents))
            js_header_filter pa_cookie_isolation.hostScopedCookies;
@endif
        }
        location ~ \.php$ {
            resolver 127.0.0.54 valid=30s;
            set $userhost {{ $user }};
            include fastcgi_params;
            fastcgi_param  SCRIPT_FILENAME /home/{{ $user }}{{ $relative_document_root }}$fastcgi_script_name;
@if (!empty($cookie_isolation_server))
            fastcgi_param  HTTP_COOKIE $pa_cookie;
@endif
            fastcgi_pass $userhost:{{ $php_port }};
            fastcgi_index index.php;
@if (!empty($cookie_isolation_shared_parents))
            js_header_filter pa_cookie_isolation.hostScopedCookies;
@endif
        }
    @endif
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
        access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx/{{ $domain }}/access.log combined;
        access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx/{{ $domain }}/bytes.log bytes;
        error_log /opt/panelalpha/shared-hosting/webserver-logs/nginx/{{ $domain }}/error.log error;

        root /home/{{ $user }}{{ $relative_document_root }};
@if (!empty($cookie_isolation_shared_parents))
        js_var $pa_cookie_shared_parents "{!! $cookie_isolation_shared_parents !!}";
@endif
@if (!empty($cookie_isolation_server))
        {!! $cookie_isolation_server !!}
@endif
        location ^~ /phpmyadmin {
            proxy_set_header X-Real-IP $remote_addr;
@if (!empty($cookie_isolation_location))
            {!! $cookie_isolation_location !!}
@endif
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
        @if(!empty($suspended))
            location / {
                error_page 503 /account-suspended.html;
                location = /account-suspended.html {
                    root  /opt/panelalpha/shared-hosting/webserver-config/document-root;
                }
                return 503;
            }
        @elseif (!empty($redirect_url))
            location / {
                return 301 "{!! $redirect_url !!}";
            }
        @else
            location /{{ $user }}-error-pages/ {
                alias /opt/panelalpha/shared-hosting/webserver-config/error-pages/;
                internal;
            }
            error_page 403 /{{ $user }}-error-pages/403.html;
            error_page 404 /{{ $user }}-error-pages/404.html;
            error_page 500 /{{ $user }}-error-pages/500.html;
            error_page 502 /{{ $user }}-error-pages/502.html;
            error_page 503 /{{ $user }}-error-pages/503.html;
            location / {
                index  index.php index.html index.htm;
                try_files $uri $uri/ /index.php?$args;
@if (!empty($cookie_isolation_shared_parents))
                js_header_filter pa_cookie_isolation.hostScopedCookies;
@endif
            }
            location ~ \.php$ {
                resolver 127.0.0.54 valid=30s;
                set $userhost {{ $user }};
                include fastcgi_params;
                fastcgi_param  SCRIPT_FILENAME /home/{{ $user }}{{ $relative_document_root }}$fastcgi_script_name;
@if (!empty($cookie_isolation_server))
                fastcgi_param  HTTP_COOKIE $pa_cookie;
@endif
                fastcgi_pass $userhost:{{ $php_port }};
                fastcgi_index index.php;
@if (!empty($cookie_isolation_shared_parents))
                js_header_filter pa_cookie_isolation.hostScopedCookies;
@endif
            }
        @endif
    }
@endif