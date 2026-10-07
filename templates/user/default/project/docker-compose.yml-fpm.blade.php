services:
  php:
    build:
      context: .
      dockerfile: ./Dockerfile
    image: ghcr.io/panelalpha/engine-user-php-fpm:v2.1.1
    pull_policy: missing
    restart: always
    hostname: {{ $user }}
    extra_hosts:
      - "host.docker.internal:host-gateway"
    container_name: {{ $user }}
    volumes:
      - /home/{{ $user }}/:/home/{{ $user }}/
      - /home/{{ $user }}/:/var/www/
      - ./log:/var/log
      - ./crontabs/www-data:/var/spool/cron/crontabs/{{ $user }}
      - ./msmtp/msmtprc:/etc/msmtprc
      - ./services/:/etc/s6/account/:ro
      - ./entrypoint-init.d/:/entrypoint-init.d/
      - ./php/ext/:/etc/php/ext/
@foreach ($php_versions as $php_version)
      - ./php/{{ $php_version }}/default.ini:/etc/php/{{ $php_version }}/fpm/conf.d/90-panelalpha-default.ini
      - ./php/{{ $php_version }}/custom.ini:/etc/php/{{ $php_version }}/fpm/conf.d/91-panelalpha-custom.ini
      - ./php/{{ $php_version }}/ioncube.ini:/etc/php/{{ $php_version }}/fpm/conf.d/00-ioncube.ini
      - ./php/{{ $php_version }}/default.ini:/etc/php/{{ $php_version }}/cli/conf.d/90-panelalpha-default.ini
      - ./php/{{ $php_version }}/custom.ini:/etc/php/{{ $php_version }}/cli/conf.d/91-panelalpha-custom.ini
      - ./php/{{ $php_version }}/ioncube.ini:/etc/php/{{ $php_version }}/cli/conf.d/00-ioncube.ini
      - ./php/{{ $php_version }}/fpm/pool.d/:/etc/php/{{ $php_version }}/fpm/pool.d/
@endforeach
    tty: true
    {{ !empty($cpu_limit) ? ("cpus: " . $cpu_limit) : "" }}
    {{ !empty($memory_limit) ? ("mem_limit: " . $memory_limit . "M") : "" }}
    {{ !empty($memory_limit) ? ("memswap_limit: " . $memory_limit . "M") : "" }}
@if ($device_read_bps || $device_write_bps)
    blkio_config:
@if ($device_read_bps)
      device_read_bps:
        - path: {{ $block_device }}
          rate: '{{ $device_read_bps }}'
@endif
@if ($device_write_bps)
      device_write_bps:
        - path: {{ $block_device }}
          rate: '{{ $device_write_bps }}'
@endif
@endif
networks: 
  default: 
    name: pash-tenants
    external: true
