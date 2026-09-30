# Nagios Core

- Image: `manios/nagios:4.5.14` (Nagios Core 4.5.14 + nagios-plugins, Apache :80).
- Volumes: `/opt/nagios/etc` (config, htpasswd), `/opt/nagios/var` (status,
  retention, logs), `/opt/Custom-Nagios-Plugins`.
- Login: HTTP basic auth from the image; set `NAGIOSADMIN_USER` /
  `NAGIOSADMIN_PASS` as project env vars to choose it.
- Edit the monitored hosts under `/opt/nagios/etc/objects/` inside the
  container; they persist on the volume.
- Known gap: `check_ping` as user `nagios` fails (`ping_group_range` is
  `65534 65534` in tenant containers, engine#442); HTTP/TCP checks work.
