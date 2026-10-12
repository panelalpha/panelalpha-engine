[www]
user = {{ $uid }}
group = {{ $gid }}

listen = 9083

listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3

catch_workers_output = yes
decorate_workers_output = no
