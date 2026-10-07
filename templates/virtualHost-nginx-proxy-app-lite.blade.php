@if ($client_area)
upstream upstream-{{ $ca_proxy_host }}-{{ $ca_proxy_port }} {
  resolver 127.0.0.54 valid=30s;
  zone upstreams 64k;
  server {{ $ca_proxy_host }}:{{ $ca_proxy_port }} resolve;
}

server {
@forelse ($ips_v4 as $ip)
@if ($ca_port)
    listen {{ $ip }}:{{ $ca_port }} ssl;
@else
    listen {{ $ip }}:80;
    listen {{ $ip }}:443 ssl;
@endif
@empty
@if ($ca_port)
    listen {{ $ca_port }} ssl;
@else
    listen 80;
    listen 443 ssl;
@endif
@endforelse
@foreach ($ips_v6 as $ip)
@if ($ca_port)
    listen [{{ $ip }}]:{{ $ca_port }} ssl;
@else
    listen [{{ $ip }}]:80;
    listen [{{ $ip }}]:443 ssl;
@endif
@endforeach
    http2 on;
    server_name {{ $ca_server_name }};
    ssl_certificate {{ $ssl_cert_file }};
    ssl_certificate_key {{ $ssl_cert_key_file }};
    access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/app-lite/access.log combined;
    error_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/app-lite/error.log error;
@if ($ca_port)
    error_page 497 https://$host:{{ $ca_port }}$request_uri;
@endif

    location / {
        resolver 127.0.0.54 valid=30s;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Upgrade $http_upgrade;
        proxy_ssl_server_name on;
        proxy_ssl_name $host;
        proxy_pass http://upstream-{{ $ca_proxy_host }}-{{ $ca_proxy_port }};
    }
}
@endif

@if ($admin_area)
upstream upstream-{{ $aa_proxy_host }}-{{ $aa_proxy_port }} {
  resolver 127.0.0.54 valid=30s;
  zone upstreams 64k;
  server {{ $aa_proxy_host }}:{{ $aa_proxy_port }} resolve;
}

server {
@forelse ($ips_v4 as $ip)
@if ($aa_port)
    listen {{ $ip }}:{{ $aa_port }} ssl;
@else
    listen {{ $ip }}:80;
    listen {{ $ip }}:443 ssl;
@endif
@empty
@if ($aa_port)
    listen {{ $aa_port }} ssl;
@else
    listen 80;
    listen 443 ssl;
@endif
@endforelse
@foreach ($ips_v6 as $ip)
@if ($aa_port)
    listen [{{ $ip }}]:{{ $aa_port }} ssl;
@else
    listen [{{ $ip }}]:80;
    listen [{{ $ip }}]:443 ssl;
@endif
@endforeach
    http2 on;
    server_name {{ $aa_server_name }};
    ssl_certificate {{ $ssl_cert_file }};
    ssl_certificate_key {{ $ssl_cert_key_file }};
    access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/app-lite/access.log combined;
    error_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/app-lite/error.log error;
@if ($aa_port)
    error_page 497 https://$host:{{ $aa_port }}$request_uri;
@endif

    location / {
        resolver 127.0.0.54 valid=30s;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Upgrade $http_upgrade;
        proxy_ssl_server_name on;
        proxy_ssl_name $host;
        proxy_pass http://upstream-{{ $aa_proxy_host }}-{{ $aa_proxy_port }};
    }
}
@endif
