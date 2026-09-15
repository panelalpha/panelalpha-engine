#!/bin/bash

ENGINE_DIR=/opt/panelalpha/shared-hosting

# No jq: this runs from logrotate inside core, whose image does not ship it.
CURRENT_WEBSERVER=$(docker inspect --format '{{ index .Config.Labels "com.panelalpha.webserver" }}' \
    $(docker compose -f $ENGINE_DIR/docker-compose.yml ps -aq sites-http) 2>/dev/null)

case $CURRENT_WEBSERVER in
    nginx)
        docker compose -f $ENGINE_DIR/docker-compose.yml exec sites-http service nginx restart
        ;;
    nginx-proxy)
        docker compose -f $ENGINE_DIR/docker-compose.yml exec sites-http service nginx restart
        ;;
    apache)
        docker compose -f $ENGINE_DIR/docker-compose.yml exec sites-http apachectl restart
        ;;
esac
