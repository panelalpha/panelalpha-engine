
user  nginx;
worker_processes  auto;

error_log  /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/error.log notice;
pid        /var/run/nginx.pid;

@if (!empty($modsecurity_enabled))
load_module modules/ngx_http_modsecurity_module.so;
@endif

# Modules the image ships with their own settings (Brotli). Globs, so an image
# without them matches nothing and this config still starts.
include /etc/nginx/modules-enabled/*.conf;

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

    # Compress text the app sent uncompressed. An answer that already carries
    # Content-Encoding passes through as it is. text/html is always included.
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/javascript text/xml application/javascript
               application/json application/ld+json application/manifest+json
               application/xml application/rss+xml application/atom+xml
               application/xhtml+xml image/svg+xml application/wasm
               font/ttf font/otf application/vnd.ms-fontobject;
    include /etc/nginx/modules-http/*.conf;

    server_names_hash_max_size 1024;
    server_names_hash_bucket_size 512;

    client_max_body_size 0;

    # The 4k page-size default turns an upstream's large headers (a Next.js or
    # SvelteKit Link: preload list, many cookies) into a 502. Bodies still stream.
    proxy_buffer_size 32k;
    proxy_buffers 8 32k;
    proxy_busy_buffers_size 64k;

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

# The tcp and udp proxy rules (NginxProxy::streamConfig). A glob, so a host
# that has not written the file yet still starts.
stream {
    include /opt/panelalpha/shared-hosting/webserver-config/nginx-proxy/stream[.]conf;
}
