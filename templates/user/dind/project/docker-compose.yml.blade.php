services:
  dind:
    build:
      context: .
      dockerfile: ./Dockerfile
    image: ghcr.io/panelalpha/engine-user-dind:v2.0.2
    pull_policy: missing
    restart: always
    hostname: {{ $user }}
    extra_hosts:
      - "host.docker.internal:host-gateway"
    {{ $isolation }}
    container_name: {{ $user }}
    # /run is ephemeral, the way it is on any Linux boot. Without this it is
    # part of the container's persistent overlay, so pidfiles outlive the
    # process that wrote them: a host reboot SIGKILLs the account before
    # dockerd can remove /run/docker.pid, and on the next start the init
    # script reads that pid, finds the number reused by something unrelated,
    # and refuses to start the daemon. The account comes up with no Docker
    # and every app in it stays down, silently. Sized because tmpfs pages
    # count against the account's own memory limit; real usage is ~276K.
    #
    # s6's scan directory gets its own tmpfs: Docker mounts tmpfs noexec, and
    # s6 has to exec each service's run script from there. /run stays noexec.
    tmpfs:
      - /run:mode=755,size=64m
      - /run/service:mode=755,size=4m,exec
    volumes:
      - /home/{{ $user }}/:/home/{{ $user }}/
      - ./entrypoint.sh:/entrypoint.sh:ro
      - ./entrypoint.d/:/entrypoint.d/:ro
      - ./daemon.json:/etc/docker/daemon.json:ro
      - ./services/:/etc/s6/account/:ro
      # lxcfs: the account's own memory, CPUs and load in /proc. Long syntax,
      # so compose never creates a missing source as a directory.
@foreach ($proc_mounts ?? [] as $procFile)
      - {type: bind, source: /var/lib/lxcfs/proc/{{ $procFile }}, target: /proc/{{ $procFile }}, read_only: true}
@endforeach
@if (!empty($proc_mounts))
      # The same files for the containers the account's own dockerd starts.
      - {type: bind, source: /var/lib/lxcfs/proc, target: /var/lib/lxcfs/proc, read_only: true}
@endif
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
# Accounts only, never core: no traffic between members, and the host holds
# them to sites-db and the registries (tenant-network-firewall.sh).
networks:
  default:
    name: pash-tenants
    external: true
