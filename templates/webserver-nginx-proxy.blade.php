
user  nginx;
worker_processes  auto;

error_log  /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/error.log notice;
pid        /var/run/nginx.pid;

@if (!empty($modsecurity_enabled))
load_module modules/ngx_http_modsecurity_module.so;
@endif

events {
    worker_connections  1024;
}

http {
@if (!empty($modsecurity_enabled))
    modsecurity on;
    modsecurity_rules_file /opt/modsecurity/main.conf;
@endif

    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;

    log_format  main  '$remote_addr - $remote_user [$time_local] "$request" '
                      '$status $body_bytes_sent "$http_referer" '
                      '"$http_user_agent" "$http_x_forwarded_for"';

    # `combined` is predefined by nginx. Defining it again makes nginx refuse to start.
    log_format bytes '[$time_local] $body_bytes_sent';

    access_log  /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/access.log  main;

    sendfile        on;
    #tcp_nopush     on;

    keepalive_timeout  65;

    #gzip  on;

    server_names_hash_max_size 1024;
    server_names_hash_bucket_size 512;

    client_max_body_size 0;

    include /opt/panelalpha/shared-hosting/webserver-config/nginx-proxy/cloudflare-realip[.]conf;
    include /etc/nginx/conf.d/*.conf;

    server {
@forelse ($ips_v4 as $ip)
        listen {{ $ip }}:80 default_server;
@empty
        listen 80 default_server;
@endforelse
@foreach ($ips_v6 as $ip)
        listen [{{ $ip }}]:80 default_server;
@endforeach
        server_name _;
        error_page 404 /404.html;
        location / {
@if ($fallback_proxy)
            resolver 127.0.0.54 valid=30s;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header Upgrade $http_upgrade;
            proxy_ssl_server_name on;
            proxy_ssl_name $host;
            set $fallbackproxyhost {{ $fallback_proxy_host }};
            proxy_pass http://$fallbackproxyhost:{{ $fallback_proxy_port }};
@else
            return 404;
@endif
        }
        location = /404.html {
            root  /opt/panelalpha/shared-hosting/webserver-config/document-root;
        }
    }
    server {
@forelse ($ips_v4 as $ip)
        listen {{ $ip }}:443 ssl default_server;
@empty
        listen 443 ssl default_server;
@endforelse
@foreach ($ips_v6 as $ip)
        listen [{{ $ip }}]:443 ssl default_server;
@endforeach
        http2 on;
        server_name _;
        ssl_certificate {{ $ssl_cert_file }};
        ssl_certificate_key {{ $ssl_cert_key_file }};
        error_page 404 /404.html;
        location / {
@if ($fallback_proxy)
            resolver 127.0.0.54 valid=30s;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header Upgrade $http_upgrade;
            proxy_ssl_server_name on;
            proxy_ssl_name $host;
            set $fallbackproxyhost {{ $fallback_proxy_host }};
            proxy_pass http://$fallbackproxyhost:{{ $fallback_proxy_port }};
@else
          return 404;
@endif
        }
        location = /404.html {
          root /opt/panelalpha/shared-hosting/webserver-config/document-root;
        }
    }
}
