<?php
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<virtualHostConfig>
@if(!empty($suspended))
  <docRoot>/opt/panelalpha/shared-hosting/webserver-config/document-root</docRoot>
  <customErrorPages>
    <errorPage>
      <errCode>503</errCode>
      <url>/account-suspended.html</url>
    </errorPage>
  </customErrorPages>
  <rewrite>
    <enable>1</enable>
    <logLevel>0</logLevel>
    <rules>
RewriteCond %{REQUEST_URI} !^/account-suspended\.html$
@if (!empty($http_acme_challenges_enabled))
RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
@endif
RewriteRule ^ - [R=503,L]
    </rules>
  </rewrite>
@endif
  <docRoot>/home/{{ $user }}{{ $relative_document_root }}</docRoot>
  <enableGzip>1</enableGzip>
  <logging>
    <log>
      <useServer>0</useServer>
      <fileName>/opt/panelalpha/shared-hosting/webserver-logs/litespeed/{{ $domain }}/error.log</fileName>
      <logLevel>ERROR</logLevel>
      <rollingSize>100M</rollingSize>
      <keepDays>7</keepDays>
      <compressArchive>1</compressArchive>
    </log>
    <accessLog>
      <useServer>0</useServer>
      <fileName>/opt/panelalpha/shared-hosting/webserver-logs/litespeed/{{ $domain }}/access.log</fileName>
      <logHeaders>3</logHeaders>
      <rollingSize>100M</rollingSize>
      <keepDays>7</keepDays>
      <compressArchive>1</compressArchive>
    </accessLog>
  </logging>
  <index>
    <useServer>0</useServer>
    <indexFiles>index.php, index.html</indexFiles>
    <autoIndex>0</autoIndex>
    <autoIndexURI>/_autoindex/default.php</autoIndexURI>
  </index>
  <customErrorPages>
    <errorPage>
      <errCode>403</errCode>
      <url>/{{ $user }}-error-pages/403.html</url>
    </errorPage>
    <errorPage>
      <errCode>404</errCode>
      <url>/{{ $user }}-error-pages/404.html</url>
    </errorPage>
    <errorPage>
      <errCode>500</errCode>
      <url>/{{ $user }}-error-pages/500.html</url>
    </errorPage>
    <errorPage>
      <errCode>502</errCode>
      <url>/{{ $user }}-error-pages/502.html</url>
    </errorPage>
    <errorPage>
      <errCode>503</errCode>
      <url>/{{ $user }}-error-pages/503.html</url>
    </errorPage>
  </customErrorPages>
  <scriptHandlerList>
    <scriptHandler>
      <suffix>php</suffix>
      <type>lsapi</type>
      <handler>{{ $user }}-lsphp</handler>
    </scriptHandler>
  </scriptHandlerList>
  <htAccess>
    <allowOverride>31</allowOverride>
    <accessFileName>.htaccess</accessFileName>
  </htAccess>
  <expires>
    <enableExpires>1</enableExpires>
  </expires>
  <security>
    <hotlinkCtrl>
      <enableHotlinkCtrl>0</enableHotlinkCtrl>
      <suffixes>gif, jpeg, jpg</suffixes>
      <allowDirectAccess>1</allowDirectAccess>
      <onlySelf>1</onlySelf>
    </hotlinkCtrl>
    <accessControl>
      <allow>*</allow>
    </accessControl>
  </security>
  <cache>
    <cacheEngine>{{ $cache_engine }}</cacheEngine>
    <storage>
      <cacheStorePath>{{ $cache_store_path }}</cacheStorePath>
      <litemage>0</litemage>
    </storage>
    <cachePolicy>
      <checkPublicCache>{{ $cache_check_public }}</checkPublicCache>
      <checkPrivateCache>{{ $cache_check_private }}</checkPrivateCache>
      <respectCacheable>0</respectCacheable>
      <enableCache>0</enableCache>
    </cachePolicy>
  </cache>
  <extProcessorList>
    <extProcessor>
      <type>lsapi</type>
      <name>{{ $user }}-lsphp</name>
      <address>{{ $user }}:{{ $php_port }}</address>
      <maxConns>{{ $lsphp_max_conns ?? 35 }}</maxConns>
      <env>PHP_LSAPI_MAX_REQUESTS=5000</env>
      <env>PHP_LSAPI_CHILDREN={{ $lsphp_max_conns ?? 35 }}</env>
      <initTimeout>1</initTimeout>
      <retryTimeout>3</retryTimeout>
      <pcKeepAliveTimeout>1</pcKeepAliveTimeout>
      <respBuffer>0</respBuffer>
      <autoStart>0</autoStart>
      <backlog>1</backlog>
      <instances>1</instances>
    </extProcessor>
    <extProcessor>
      <type>proxy</type>
      <name>{{ $user }}-phpmyadmin</name>
      <address>http://phpmyadmin-users.shared-hosting.palocal:80</address>
      <maxConns>10</maxConns>
      <initTimeout>5</initTimeout>
      <retryTimeout>5</retryTimeout>
      <respBuffer>0</respBuffer>
    </extProcessor>
  </extProcessorList>
    <contextList>
@if (!empty($http_acme_challenges_enabled))
    <context>
      <type>static</type>
      <uri>/.well-known/acme-challenge/</uri>
      <location>{{ $http_acme_challenges_dir }}/</location>
      <allowBrowse>1</allowBrowse>
      <addDefaultCharset>off</addDefaultCharset>
    </context>
@endif
    <context>
      <uri>/{{ $user }}-error-pages/</uri>
      <location>/opt/panelalpha/shared-hosting/webserver-config/error-pages/</location>
      <allowBrowse>1</allowBrowse>
    </context>
  </contextList>
  <rewrite>
    <enable>1</enable>
    <logLevel>0</logLevel>
    <rules>
RewriteCond %{QUERY_STRING} pmassotoken=
RewriteRule .* - [E=pmapass:1]
RewriteCond %{HTTP_COOKIE} PMASignonSession
RewriteRule .* - [E=pmapass:1]
RewriteCond %{REQUEST_URI} ^/phpmyadmin$
RewriteRule ^ /phpmyadmin/ [R=301,L]
RewriteCond %{REQUEST_URI} ^/phpmyadmin/
RewriteCond %{ENV:pmapass} =1
RewriteRule ^/phpmyadmin/(.*)$ http://{{ $user }}-phpmyadmin/$1 [P,L]
    </rules>
  </rewrite>
@if (!empty($ssl_enabled))
  <vhssl>
    <keyFile>/opt/panelalpha/shared-hosting/users/{{ $user }}/ssl-certs/{{ $domain }}.key</keyFile>
    <certFile>/opt/panelalpha/shared-hosting/users/{{ $user }}/ssl-certs/{{ $domain }}.pem</certFile>
  </vhssl>
@endif
  <frontPage>
    <enable>0</enable>
    <disableAdmin>0</disableAdmin>
  </frontPage>
  <awstats>
    <updateMode>0</updateMode>
    <workingDir>$VH_ROOT/awstats</workingDir>
    <awstatsURI>/awstats/</awstatsURI>
    <siteDomain>localhost</siteDomain>
    <siteAliases>127.0.0.1 localhost</siteAliases>
    <updateInterval>86400</updateInterval>
    <updateOffset>0</updateOffset>
    <securedConn>0</securedConn>
  </awstats>
</virtualHostConfig>