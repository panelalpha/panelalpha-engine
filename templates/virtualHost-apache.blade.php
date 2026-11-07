<VirtualHost @forelse ($ips_v4 as $ip) {{ $ip }}:80 @empty *:80 @endforelse @foreach ($ips_v6 as $ip) [{{ $ip }}]:80 @endforeach>
    ServerName {{ $domain }}
    @if (!empty($aliases))
        ServerAlias {{ implode(' ', $aliases) }}
    @endif
    ServerAdmin webmaster@localhost
    DocumentRoot /home/{{ $user }}{{ $relative_document_root }}
    ErrorLog /opt/panelalpha/shared-hosting/webserver-logs/apache/{{ $domain }}/error.log
    CustomLog /opt/panelalpha/shared-hosting/webserver-logs/apache/{{ $domain }}/access.log combined
@if (!empty($cookie_isolation_request))
    {!! $cookie_isolation_request !!}
@endif
@if (!empty($cookie_isolation_response))
    {!! $cookie_isolation_response !!}
@endif
    RewriteEngine On
@if (!empty($http_acme_challenges_enabled))
    Alias /.well-known/acme-challenge {{ $http_acme_challenges_dir }}
    <Directory "{{ $http_acme_challenges_dir }}">
        Options -Indexes
        AllowOverride None
        Require all granted
        ForceType text/plain
    </Directory>
@endif
    SetEnvIfExpr "%{QUERY_STRING} =~ /pmassotoken=/" pmapass=1
    SetEnvIfExpr "%{HTTP_COOKIE} =~ /PMASignonSession/" pmapass=1
    RewriteCond %{REQUEST_URI} ^/phpmyadmin$
    RewriteRule ^ /phpmyadmin/ [R=301,L]
    RewriteCond %{REQUEST_URI} ^/phpmyadmin/
    RewriteCond %{ENV:pmapass} =1
    RewriteRule ^/phpmyadmin/(.*)$ http://phpmyadmin-users.shared-hosting.palocal/$1 [P,L]
    <Directory /home/{{ $user }}{{ $relative_document_root }}>
        DirectoryIndex index.php index.html
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Alias /{{ $user }}-error-pages /opt/panelalpha/shared-hosting/webserver-config/error-pages
    <Directory /opt/panelalpha/shared-hosting/webserver-config/error-pages>
        Require all granted
    </Directory>
    ErrorDocument 403 /{{ $user }}-error-pages/403.html
    ErrorDocument 404 /{{ $user }}-error-pages/404.html
    ErrorDocument 500 /{{ $user }}-error-pages/500.html
    ErrorDocument 502 /{{ $user }}-error-pages/502.html
    ErrorDocument 503 /{{ $user }}-error-pages/503.html
    <FilesMatch "\.php$">
        CGIPassAuth On
        SetHandler "proxy:fcgi://{{ $user }}:{{ $php_port }}"
    </FilesMatch>
@if(!empty($suspended))
    <Directory /opt/panelalpha/shared-hosting/webserver-config/document-root>
        Require all granted
        Options -Indexes
    </Directory>
    DocumentRoot /opt/panelalpha/shared-hosting/webserver-config/document-root
    ErrorDocument 503 /account-suspended.html
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/account-suspended\.html$
    @if (!empty($http_acme_challenges_enabled))
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    @endif
    RewriteRule ^ - [R=503,L]
@endif
</VirtualHost>
@if (!empty($ssl_enabled))
<VirtualHost @forelse ($ips_v4 as $ip) {{ $ip }}:443 @empty *:443 @endforelse @foreach ($ips_v6 as $ip) [{{ $ip }}]:443 @endforeach>
    ServerName {{ $domain }}
    @if (!empty($aliases))
        ServerAlias {{ implode(' ', $aliases) }}
    @endif
    ServerAdmin webmaster@localhost
    DocumentRoot /home/{{ $user }}{{ $relative_document_root }}
    ErrorLog /opt/panelalpha/shared-hosting/webserver-logs/apache/{{ $domain }}/error.log
    CustomLog /opt/panelalpha/shared-hosting/webserver-logs/apache/{{ $domain }}/access.log combined
    SSLEngine on
    SSLVerifyClient none
    SSLCertificateFile {{ $ssl_cert_file }}
    SSLCertificateKeyFile {{ $ssl_cert_key_file }}
    SSLCertificateChainFile {{ $ssl_cert_ca_file }}
@if (!empty($cookie_isolation_request))
    {!! $cookie_isolation_request !!}
@endif
@if (!empty($cookie_isolation_response))
    {!! $cookie_isolation_response !!}
@endif
    RewriteEngine On
    SetEnvIfExpr "%{QUERY_STRING} =~ /pmassotoken=/" pmapass=1
    SetEnvIfExpr "%{HTTP_COOKIE} =~ /PMASignonSession/" pmapass=1
    RewriteCond %{REQUEST_URI} ^/phpmyadmin$
    RewriteRule ^ /phpmyadmin/ [R=301,L]
    RewriteCond %{REQUEST_URI} ^/phpmyadmin/
    RewriteCond %{ENV:pmapass} =1
    RewriteRule ^/phpmyadmin/(.*)$ http://phpmyadmin-users.shared-hosting.palocal/$1 [P,L]
    <Directory /home/{{ $user }}{{ $relative_document_root }}>
        DirectoryIndex index.php index.html
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Alias /{{ $user }}-error-pages /opt/panelalpha/shared-hosting/webserver-config/error-pages
    <Directory /opt/panelalpha/shared-hosting/webserver-config/error-pages>
        Require all granted
    </Directory>
    ErrorDocument 403 /{{ $user }}-error-pages/403.html
    ErrorDocument 404 /{{ $user }}-error-pages/404.html
    ErrorDocument 500 /{{ $user }}-error-pages/500.html
    ErrorDocument 502 /{{ $user }}-error-pages/502.html
    ErrorDocument 503 /{{ $user }}-error-pages/503.html
    <FilesMatch "\.php$">
        CGIPassAuth On
        SetHandler "proxy:fcgi://{{ $user }}:{{ $php_port }}"
    </FilesMatch>
@if(!empty($suspended))
    <Directory /opt/panelalpha/shared-hosting/webserver-config/document-root>
        Require all granted
        Options -Indexes
    </Directory>
    DocumentRoot /opt/panelalpha/shared-hosting/webserver-config/document-root
    ErrorDocument 503 /account-suspended.html
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/account-suspended\.html$
    RewriteRule ^ - [R=503,L]
@endif
</VirtualHost>
@endif
