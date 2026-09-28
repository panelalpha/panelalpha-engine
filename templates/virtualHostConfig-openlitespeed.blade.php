@if(!empty($suspended))
docRoot /opt/panelalpha/shared-hosting/webserver-config/document-root
errorpage 503 {
  url /account-suspended.html
}
rewrite  {
  enable 1
  logLevel 0
@if (!empty($http_acme_challenges_enabled))
  RewriteRule ^/(?!account-suspended.html)(?!\.well-known/acme-challenge/).*$ - [R=503,L]
@else
  RewriteRule ^/(?!account-suspended.html).*$ - [R=503,L]
@endif
}
@endif
docRoot                   /home/{{ $user }}{{ $relative_document_root }}
vhDomain                  {{ $domain }}
@if (!empty($aliases))
vhAliases {{ implode(', ', $aliases) }}
@endif
enableGzip                1

errorlog /opt/panelalpha/shared-hosting/webserver-logs/openlitespeed/{{ $domain }}/error.log {
  logLevel                WARNING
  useServer               0
  rollingSize             100M
  keepDays                30
  compressArchive         1
}

accesslog /opt/panelalpha/shared-hosting/webserver-logs/openlitespeed/{{ $domain }}/access.log {
  useServer               0
  rollingSize             100M
  keepDays                30
  compressArchive         1
}

index  {
  useServer               0
  indexFiles              index.php, index.html
  autoIndex               0
  autoIndexURI            /_autoindex/default.php
}

errorpage 403 {
  url                     /{{ $user }}-error-pages/403.html
}
errorpage 404 {
  url                     /{{ $user }}-error-pages/404.html
}
errorpage 500 {
  url                     /{{ $user }}-error-pages/500.html
}
errorpage 502 {
  url                     /{{ $user }}-error-pages/502.html
}
errorpage 503 {
  url                     /{{ $user }}-error-pages/503.html
}

scripthandler  {
  add                     lsapi:{{ $user }}-{{ $domain }} php
}

expires  {
  enableExpires           1
}

accessControl  {
  allow                   *
}

extprocessor {{ $user }}-{{ $domain }} {
  type                    lsapi
  address                 {{ $user }}:{{ $php_port }}
  maxConns                {{ $lsphp_max_conns ?? 35 }}
  initTimeout             1
  retryTimeout            1
  respBuffer              0
  autoStart               0
}

extprocessor {{ $user }}-phpmyadmin {
  type                    proxy
  address                 http://phpmyadmin-users.shared-hosting.palocal:80
  maxConns                10
  initTimeout             5
  retryTimeout            5
  respBuffer              0
}

@if (!empty($http_acme_challenges_enabled))
context /.well-known/acme-challenge/ {
  type                    static
  location                {{ $http_acme_challenges_dir }}/
  allowBrowse             1
  addDefaultCharset       off
}
@endif

context / {
  location                $DOC_ROOT/
  allowBrowse             1
  rewrite  {
    enable                  1
    logLevel                0
    RewriteEngine On
    RewriteBase /
    RewriteRule ^index\\.php$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /index.php [L,QSA]
    RewriteFile .htaccess
  }
}

context /{{ $user }}-error-pages/ {
  location                /opt/panelalpha/shared-hosting/webserver-config/error-pages/
  allowBrowse             1
}

rewrite  {
  enable                  1
  autoLoadHtaccess        1
  logLevel                0
  RewriteEngine On
  RewriteCond %{QUERY_STRING} pmassotoken=
  RewriteRule .* - [E=pmapass:1]
  RewriteCond %{HTTP_COOKIE} PMASignonSession
  RewriteRule .* - [E=pmapass:1]
  RewriteCond %{REQUEST_URI} ^/phpmyadmin$
  RewriteRule ^ /phpmyadmin/ [R=301,L]
  RewriteCond %{REQUEST_URI} ^/phpmyadmin/
  RewriteCond %{ENV:pmapass} =1
  RewriteRule ^/phpmyadmin/(.*)$ http://{{ $user }}-phpmyadmin/$1 [P,L]
}

@if (!empty($ssl_enabled))
vhssl  {
  keyFile                 {{ $ssl_cert_key_file }}
  certFile                {{ $ssl_cert_pem_file }}
}
@endif

module cache {
  storagePath {{ $cache_store_path }}
  checkPrivateCache 1
  checkPublicCache 1
  maxCacheObjSize 10000000
  maxStaleAge 0
  qsCache 1
  reqCookieCache 1
  respCookieCache 1
  ignoreReqCacheCtrl 0
  ignoreRespCacheCtrl 0
  enableCache 1
  expireInSeconds 43200
  enablePrivateCache 1
  privateExpireInSeconds 3600
  ls_enabled {{ $cache_enabled }}
}